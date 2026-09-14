<?php

namespace Tests\Feature\Email;

use App\Jobs\SendFacultyLensEmailJob;
use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Notifications\NotificationType;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\TestCase;

/**
 * Event → SendFacultyLensEmailJob on the `emails` queue → Mailable, with retry/failure semantics:
 * temporary transport errors retry with backoff, permanent ones fail once, the database driver works end to end,
 * and a rolled-back domain transaction never leaves a delivery behind.
 */
class EmailQueueTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    public function test_notify_pushes_email_job_on_the_emails_queue_after_commit(): void
    {
        Queue::fake();
        config(['notifications.queue.enabled' => false, 'email.queue.connection' => 'redis', 'email.queue.name' => 'emails']);
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $this->notify($user);

        $delivery = $this->lastDelivery();
        $this->assertSame(EmailDelivery::PENDING, $delivery->status);
        $this->assertNotNull($delivery->queued_at);
        Queue::assertPushed(SendFacultyLensEmailJob::class, function (SendFacultyLensEmailJob $job) use ($delivery) {
            return $job->deliveryId === $delivery->id
                && $job->template === 'report-ready'
                && $job->connection === 'redis'
                && $job->queue === 'emails'
                && $job->afterCommit === true
                && $job->tries === (int) config('email.queue.tries')
                && $job->backoff === config('email.queue.backoff')
                && $job->tags() === ['email', 'template:report-ready', 'delivery:' . $delivery->id];
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMAIL_QUEUED', 'entity_id' => $delivery->id]);
    }

    public function test_job_runs_mailable_and_marks_sent(): void
    {
        Mail::fake();
        $user = $this->faculty();
        $delivery = EmailDelivery::create(['user_id' => $user->id, 'type' => 'REPORT_GENERATED', 'category' => 'REPORT', 'template' => 'report-ready', 'recipient' => $user->email, 'subject' => 'FacultyLens — Your Report Is Ready', 'status' => EmailDelivery::PENDING, 'idempotency_key' => 'k', 'queued_at' => now()]);

        (new SendFacultyLensEmailJob($delivery->id, 'report-ready', $delivery->subject, ['recipient_name' => $user->name, 'report_type' => 'Assessment']))->handle(app(AuditLogService::class));

        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->hasTo($user->email) && $m->deliveryId === $delivery->id);
        $delivery->refresh();
        $this->assertSame(EmailDelivery::SENT, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNull($delivery->error_code);
    }

    public function test_database_queue_end_to_end_with_worker(): void
    {
        Mail::fake();
        config(['queue.default' => 'database', 'email.queue.connection' => 'database', 'email.queue.name' => 'emails', 'notifications.queue.enabled' => false]);
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $this->notify($user);
        $this->notify($user); // duplicate producer event → no second job

        $this->assertSame(1, DB::table('jobs')->where('queue', 'emails')->count());
        $this->assertSame(EmailDelivery::PENDING, $this->lastDelivery()->status);
        Mail::assertNothingSent();

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'emails')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        Mail::assertSent(FacultyLensMail::class, 1);
        $this->assertSame(EmailDelivery::SENT, $this->lastDelivery()->status);
    }

    public function test_temporary_transport_error_is_rethrown_for_retry_and_row_stays_pending(): void
    {
        $this->swapMailerThrowing(new TransportException('Connection could not be established with host "smtp.gmail.com:587": Connection timed out'));
        $user = $this->faculty();
        $delivery = $this->pendingDelivery($user);
        $job = new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []);

        try {
            $job->handle(app(AuditLogService::class));
            $this->fail('temporary errors must propagate so the queue retries');
        } catch (TransportException) {
        }

        $delivery->refresh();
        $this->assertSame(EmailDelivery::PENDING, $delivery->status);
        $this->assertSame('SMTP_TIMEOUT', $delivery->error_code);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringNotContainsString('password', strtolower((string) $delivery->error_message));
    }

    public function test_permanent_error_fails_immediately_without_retry(): void
    {
        $this->swapMailerThrowing(new RfcComplianceException('Email "not-an-address" does not comply with addr-spec of RFC 2822.'));
        $user = $this->faculty();
        $delivery = $this->pendingDelivery($user);

        (new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []))->handle(app(AuditLogService::class));

        $delivery->refresh();
        $this->assertSame(EmailDelivery::FAILED, $delivery->status);
        $this->assertSame('INVALID_RECIPIENT', $delivery->error_code);
        $this->assertNotNull($delivery->failed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'EMAIL_FAILED', 'entity_id' => $delivery->id]);
    }

    public function test_authentication_rejection_is_permanent_and_sanitised(): void
    {
        config(['mail.mailers.smtp.password' => 'the-real-app-password']);
        $this->swapMailerThrowing(new UnexpectedResponseException('Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted. AUTH PLAIN dGhlLXJlYWwtYXBwLXBhc3N3b3Jk password=the-real-app-password".', 535));
        $user = $this->faculty();
        $delivery = $this->pendingDelivery($user);

        (new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []))->handle(app(AuditLogService::class));

        $delivery->refresh();
        $this->assertSame(EmailDelivery::FAILED, $delivery->status);
        $this->assertSame('SMTP_AUTHENTICATION_FAILED', $delivery->error_code);
        $this->assertStringNotContainsString('the-real-app-password', (string) $delivery->error_message);
        $this->assertStringNotContainsString(base64_encode('the-real-app-password'), (string) $delivery->error_message);
    }

    public function test_retries_exhausted_marks_failed(): void
    {
        config(['email.queue.tries' => 2]);
        $this->swapMailerThrowing(new TransportException('Connection refused'));
        $user = $this->faculty();
        $delivery = $this->pendingDelivery($user);

        foreach ([1, 2] as $attempt) {
            try {
                (new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []))->handle(app(AuditLogService::class));
            } catch (TransportException) {
            }
        }

        $delivery->refresh();
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame(EmailDelivery::FAILED, $delivery->status);
        $this->assertSame('SMTP_CONNECTION_FAILED', $delivery->error_code);
    }

    public function test_failed_callback_marks_row_failed(): void
    {
        $user = $this->faculty();
        $delivery = $this->pendingDelivery($user);

        (new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []))->failed(new TransportException('DNS: getaddrinfo for smtp.gmail.com failed'));

        $delivery->refresh();
        $this->assertSame(EmailDelivery::FAILED, $delivery->status);
        $this->assertSame('DNS_RESOLUTION_FAILED', $delivery->error_code);
    }

    public function test_no_delivery_when_the_domain_transaction_rolls_back(): void
    {
        Mail::fake();
        config(['notifications.queue.enabled' => false]);
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        try {
            DB::transaction(function () use ($user) {
                $this->notify($user);
                throw new \RuntimeException('domain operation failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, EmailDelivery::count());
        Mail::assertNothingSent();
    }

    public function test_email_job_failure_does_not_touch_other_jobs_or_the_notification(): void
    {
        config(['queue.default' => 'database', 'email.queue.connection' => 'database', 'notifications.queue.enabled' => false]);
        $this->swapMailerThrowing(new RfcComplianceException('bad address'));
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $notification = $this->notify($user);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true, '--sleep' => 0]);

        $this->assertNotNull($notification->fresh(), 'in-app row untouched');
        $this->assertSame(EmailDelivery::FAILED, $this->lastDelivery()->status);
    }

    // ------------------------------------------------------------------ helpers

    protected function pendingDelivery($user): EmailDelivery
    {
        return EmailDelivery::create(['user_id' => $user->id, 'type' => 'REPORT_GENERATED', 'category' => 'REPORT', 'template' => 'report-ready', 'recipient' => $user->email, 'subject' => 's', 'status' => EmailDelivery::PENDING, 'idempotency_key' => 'k-' . uniqid(), 'queued_at' => now()]);
    }

    protected function swapMailerThrowing(\Throwable $e): void
    {
        $mailer = new class($e) {
            public function __construct(private \Throwable $e) {}

            public function to(...$args): self
            {
                return $this;
            }

            public function send(...$args): void
            {
                throw $this->e;
            }
        };
        Mail::swap(new class($mailer) {
            public function __construct(private object $mailer) {}

            public function __call($method, $args)
            {
                return $this->mailer->$method(...$args);
            }
        });
    }
}
