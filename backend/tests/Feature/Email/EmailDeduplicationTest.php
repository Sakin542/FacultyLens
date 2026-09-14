<?php

namespace Tests\Feature\Email;

use App\Jobs\SendFacultyLensEmailJob;
use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Notifications\NotificationType;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Idempotency and storm protection: identical events collapse onto one delivery, a redelivered job never sends
 * twice, and per-user ceilings turn bursts into CANCELLED rows instead of mailbox floods.
 */
class EmailDeduplicationTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['notifications.queue.enabled' => false]);
    }

    public function test_same_event_emitted_twice_sends_one_email(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $this->notify($user);
        $this->notify($user);
        $this->notify($user);

        Mail::assertSent(FacultyLensMail::class, 1);
        $this->assertSame(1, EmailDelivery::count());
    }

    public function test_dedupe_is_per_recipient_and_per_entity(): void
    {
        $a = $this->faculty();
        $b = $this->faculty();
        $this->enableEmail($a, NotificationType::REPORT_GENERATED);
        $this->enableEmail($b, NotificationType::REPORT_GENERATED);

        $this->notify($a);
        $this->notify($b);
        $this->notify($a, NotificationType::REPORT_GENERATED, ['entity_id' => 43, 'data' => ['report_id' => 43]]);

        Mail::assertSent(FacultyLensMail::class, 3);
        $this->assertSame(3, EmailDelivery::count());
    }

    public function test_redelivered_job_does_not_send_a_sent_delivery_again(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);
        $this->notify($user);
        $delivery = $this->lastDelivery();
        $this->assertSame(EmailDelivery::SENT, $delivery->status);

        // worker crash after send → the same job is redelivered by the queue
        (new SendFacultyLensEmailJob($delivery->id, $delivery->template, $delivery->subject, ['recipient_name' => 'x']))->handle(app(\App\Services\AuditLogService::class));
        (new SendFacultyLensEmailJob($delivery->id, $delivery->template, $delivery->subject, ['recipient_name' => 'x']))->handle(app(\App\Services\AuditLogService::class));

        Mail::assertSent(FacultyLensMail::class, 1);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_cancelled_delivery_is_never_sent(): void
    {
        $user = $this->faculty();
        $delivery = EmailDelivery::create(['user_id' => $user->id, 'type' => 'REPORT_GENERATED', 'template' => 'report-ready', 'recipient' => $user->email, 'subject' => 's', 'status' => EmailDelivery::CANCELLED, 'idempotency_key' => 'k1']);

        (new SendFacultyLensEmailJob($delivery->id, 'report-ready', 's', []))->handle(app(\App\Services\AuditLogService::class));

        Mail::assertNothingSent();
        $this->assertSame(EmailDelivery::CANCELLED, $delivery->fresh()->status);
    }

    public function test_per_user_hourly_limit_cancels_the_overflow_but_keeps_in_app(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);
        config(['email.rate_limit.per_user_per_hour' => 3, 'email.rate_limit.burst_max_per_type' => 0]);

        for ($i = 1; $i <= 5; $i++) {
            $n = $this->notify($user, NotificationType::REPORT_GENERATED, ['entity_id' => $i, 'data' => ['report_id' => $i]]);
            $this->assertNotNull($n, 'in-app notification is always written');
        }

        Mail::assertSent(FacultyLensMail::class, 3);
        $this->assertSame(3, EmailDelivery::where('status', EmailDelivery::SENT)->count());
        $this->assertSame(2, EmailDelivery::where('status', EmailDelivery::CANCELLED)->where('error_code', 'RATE_LIMITED')->count());
    }

    public function test_burst_of_the_same_type_is_capped_within_the_window(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::GRADING_COMPLETED);
        config(['email.rate_limit.per_user_per_hour' => 100, 'email.rate_limit.burst_window_seconds' => 60, 'email.rate_limit.burst_max_per_type' => 2]);

        for ($i = 1; $i <= 4; $i++) {
            $this->notify($user, NotificationType::GRADING_COMPLETED, ['title' => 'Grading suggestion ready', 'message' => 'm', 'entity_type' => 'grading_result', 'entity_id' => $i, 'data' => []]);
        }

        Mail::assertSent(FacultyLensMail::class, 2);
        $this->assertSame(2, EmailDelivery::where('status', EmailDelivery::CANCELLED)->count());
    }

    public function test_security_emails_are_not_throttled_by_the_regular_ceiling(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);
        config(['email.rate_limit.per_user_per_hour' => 1, 'email.rate_limit.burst_max_per_type' => 0]);

        $this->notify($user);
        $this->notify($user, NotificationType::REPORT_GENERATED, ['entity_id' => 2, 'data' => []]);
        $this->assertSame(1, EmailDelivery::where('status', EmailDelivery::CANCELLED)->count());

        $this->notify($user, NotificationType::SECURITY_ALERT, ['title' => 'Password changed', 'message' => 'm', 'dedupe_key' => 'sec-1', 'data' => []]);
        app(EmailService::class)->sendPasswordReset($user, 'tok');

        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'security-alert');
        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'reset-password');
        $this->assertSame(3, EmailDelivery::where('status', EmailDelivery::SENT)->count());
    }

    public function test_notification_without_entity_gets_a_time_bucketed_key(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::SYSTEM_ALERT);

        $this->notify($user, NotificationType::SYSTEM_ALERT, ['title' => 'Maintenance', 'message' => 'Tonight 22:00', 'entity_type' => null, 'entity_id' => null, 'data' => []]);
        $this->notify($user, NotificationType::SYSTEM_ALERT, ['title' => 'Maintenance', 'message' => 'Tonight 22:00', 'entity_type' => null, 'entity_id' => null, 'data' => []]);

        Mail::assertSent(FacultyLensMail::class, 1);
    }
}
