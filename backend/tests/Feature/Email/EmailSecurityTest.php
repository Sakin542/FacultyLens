<?php

namespace Tests\Feature\Email;

use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Notifications\NotificationType;
use App\Services\Email\EmailContentResolver;
use App\Services\Email\EmailLogSanitizer;
use App\Services\Email\EmailPlainText;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Credential leakage, header/HTML injection, privacy of academic data, and log hygiene.
 */
class EmailSecurityTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    private const SECRET = 'gmail-app-password-XYZ123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.mailers.smtp.password' => self::SECRET, 'mail.mailers.smtp.username' => 'sender@example.com', 'mail.mailers.smtp.host' => 'smtp.gmail.com', 'notifications.queue.enabled' => false]);
    }

    public function test_smtp_password_never_appears_in_rendered_email_api_or_database(): void
    {
        Mail::fake();
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);
        $this->notify($user);

        $mail = Mail::sent(FacultyLensMail::class)->first();
        $this->assertStringNotContainsString(self::SECRET, $mail->renderHtml());
        $this->assertStringNotContainsString(self::SECRET, $mail->renderText());
        $this->assertStringNotContainsString(self::SECRET, json_encode($mail->context));

        foreach (['/api/email/status', '/api/email/deliveries', '/api/notification-preferences'] as $path) {
            $body = $this->actingAs($user)->getJson($path)->assertOk()->getContent();
            $this->assertStringNotContainsString(self::SECRET, $body, $path);
            $this->assertStringNotContainsString('smtp.gmail.com', $body, $path);
        }

        foreach (DB::table('email_deliveries')->get() as $row) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($row));
        }
        foreach (DB::table('audit_logs')->get() as $row) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($row));
        }
    }

    public function test_log_sanitizer_masks_credentials_auth_lines_dsns_and_tokens(): void
    {
        $this->assertStringNotContainsString(self::SECRET, EmailLogSanitizer::string('Failed: password=' . self::SECRET . ' rejected'));
        $this->assertStringNotContainsString(self::SECRET, EmailLogSanitizer::string('Transport said: ' . self::SECRET));
        $this->assertStringNotContainsString(base64_encode(self::SECRET), EmailLogSanitizer::string('AUTH PLAIN ' . base64_encode(self::SECRET)));
        $this->assertSame('AUTH LOGIN ********', EmailLogSanitizer::string('AUTH LOGIN dXNlcm5hbWU='));
        $this->assertSame('smtp://user:********@smtp.gmail.com:587', EmailLogSanitizer::string('smtp://user:s3cret-pass@smtp.gmail.com:587'));
        $this->assertSame('reset?token=********&email=a', EmailLogSanitizer::string('reset?token=abcdef0123456789&email=a'));

        $ctx = EmailLogSanitizer::context(['type' => 'X', 'mail_password' => self::SECRET, 'nested' => ['token' => 't', 'ok' => 1], 'reset_url' => 'https://x/reset?token=abc']);
        $this->assertSame('********', $ctx['mail_password']);
        $this->assertSame('********', $ctx['nested']['token']);
        $this->assertSame('********', $ctx['reset_url']);
        $this->assertSame(1, $ctx['nested']['ok']);
        $this->assertSame('university.edu', EmailLogSanitizer::domain('Someone@University.edu'));
    }

    public function test_send_failure_logs_do_not_contain_the_secret(): void
    {
        Log::spy();
        Mail::swap(new class {
            public function to(...$a): self
            {
                return $this;
            }

            public function send(...$a): void
            {
                throw new \Symfony\Component\Mailer\Exception\UnexpectedResponseException('535 Username and Password not accepted. AUTH PLAIN ' . base64_encode("\0sender@example.com\0" . EmailSecurityTest::SECRET) . ' ' . EmailSecurityTest::SECRET, 535);
            }
        });
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED);

        $this->notify($user);

        $this->assertSame(EmailDelivery::FAILED, $this->lastDelivery()->status);
        $this->assertStringNotContainsString(self::SECRET, (string) $this->lastDelivery()->error_message);
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            $blob = $message . json_encode($context);

            return !str_contains($blob, EmailSecurityTest::SECRET) && !str_contains($blob, base64_encode(EmailSecurityTest::SECRET));
        })->atLeast()->once();
    }

    public function test_recipient_header_injection_is_rejected(): void
    {
        $service = app(EmailService::class);
        foreach (["victim@example.com\r\nBcc: attacker@evil.example", "a@b.co\nCc: c@d.ef", "a@b.co%0ABcc:x@y.z", 'not an email', '', str_repeat('a', 200) . '@x.co'] as $bad) {
            try {
                $service->validateRecipient($bad);
                $this->fail("accepted invalid recipient: " . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('ok@university.edu', $service->validateRecipient('  OK@University.edu '));
    }

    public function test_subject_and_html_injection_are_neutralised(): void
    {
        Mail::fake();
        $user = $this->faculty(['name' => '<img src=x onerror=alert(1)>']);
        $this->enableEmail($user, NotificationType::FACULTY_FEEDBACK_RECEIVED);

        $this->notify($user, NotificationType::FACULTY_FEEDBACK_RECEIVED, [
            'title' => "Feedback\r\nBcc: attacker@evil.example",
            'message' => '<script>alert("xss")</script><a href="javascript:alert(1)">click</a>',
            'action_url' => 'javascript:alert(1)',
            'entity_type' => 'recommendation', 'entity_id' => 9, 'data' => ['actor_name' => '"><svg onload=alert(1)>'],
        ]);

        $mail = Mail::sent(FacultyLensMail::class)->first();
        $html = $mail->renderHtml();
        $this->assertStringNotContainsString("\r\n", $mail->subjectLine);
        $this->assertStringNotContainsString('Bcc:', $mail->subjectLine);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="' . rtrim(config('email.frontend_url'), '/') . '/dashboard"', $html, 'unsafe action_url falls back to the app root');
    }

    public function test_student_data_and_secrets_never_reach_the_template_context(): void
    {
        $resolver = app(EmailContentResolver::class);
        $context = $resolver->contextForNotification([
            'type' => 'PERFORMANCE_ANALYSIS_COMPLETED', 'category' => 'PERFORMANCE', 'title' => 't', 'message' => 'm', 'action_url' => '/x',
            'data' => ['assessment_id' => 1, 'student_name' => 'John Doe', 'student_identifier' => '20231234', 'awarded_marks' => 23, 'answer_text' => 'private', 'api_key' => 'k', 'password' => 'p', 'token' => 'tok', 'gap_count' => 2],
        ]);

        $this->assertSame(['assessment_id' => 1, 'gap_count' => 2], $context['data']);
        $flat = strtolower(json_encode($context));
        foreach (['john doe', '20231234', 'private', 'api_key', 'password', '"tok"'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat, $needle);
        }
    }

    public function test_reset_url_survives_but_no_other_secret_like_key_reaches_the_job_payload(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $user = $this->faculty();

        app(EmailService::class)->send($user->email, 'PASSWORD_RESET', ['reset_url' => 'https://app/reset-password?token=T', 'password' => 'should-drop', 'api_key' => 'drop'], ['user' => $user, 'idempotency_key' => 'x']);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendFacultyLensEmailJob::class, function ($job) {
            return $job->context === ['reset_url' => 'https://app/reset-password?token=T'];
        });
    }

    public function test_plain_text_conversion_is_safe_and_readable(): void
    {
        $text = EmailPlainText::fromHtml('<html><head><style>p{}</style></head><body><div data-plain-text="skip" style="display:none">preheader</div><h1>Title</h1><p>Hello &amp; welcome</p><a href="https://x.example/a?b=1&amp;c=2">Open</a><table><tr><td>A</td><td>B</td></tr></table></body></html>');
        $this->assertStringNotContainsString('preheader', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString("Title", $text);
        $this->assertStringContainsString('Hello & welcome', $text);
        $this->assertStringContainsString('Open: https://x.example/a?b=1&c=2', $text);
        $this->assertStringContainsString('A B', $text);
    }

    public function test_delivery_api_masks_recipient_and_hides_internal_key(): void
    {
        $user = $this->faculty(['email' => 'faculty.member@university.edu']);
        $d = EmailDelivery::create(['user_id' => $user->id, 'type' => 'REPORT_GENERATED', 'template' => 'report-ready', 'recipient' => $user->email, 'subject' => 's', 'status' => 'SENT', 'idempotency_key' => 'secret-key', 'error_message' => 'x']);

        $json = $this->actingAs($user)->getJson('/api/email/deliveries/' . $d->id)->assertOk()->json('data');
        $this->assertSame('fa************@university.edu', $json['recipient']);
        $this->assertArrayNotHasKey('idempotency_key', $json);
        $this->assertArrayNotHasKey('error_message', $json, 'raw transport messages are not exposed through the API');
    }
}
