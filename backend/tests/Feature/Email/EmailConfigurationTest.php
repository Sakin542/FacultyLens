<?php

namespace Tests\Feature\Email;

use App\Mail\FacultyLensMail;
use App\Services\Email\EmailContentResolver;
use App\Services\Email\EmailLogSanitizer;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sender configuration, Mailable rendering (HTML + plain text) for every template, brand layout, masking.
 * No test here talks to Gmail; the single live SMTP check is opt-in via EMAIL_LIVE_TESTING=true.
 */
class EmailConfigurationTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    public function test_mail_configuration_is_read_from_environment_keys(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.encryption' => 'tls',
            'mail.mailers.smtp.username' => 'sender@example.com',
            'mail.mailers.smtp.password' => 'app-password-secret',
            'mail.from.address' => 'sender@example.com',
            'mail.from.name' => 'FacultyLens',
        ]);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('tls', config('mail.mailers.smtp.encryption'));
        $this->assertSame('sender@example.com', config('mail.from.address'));
        $this->assertSame('FacultyLens', config('mail.from.name'));
        $this->assertArrayHasKey('mailpit', config('mail.mailers'), 'capture mailer for E2E');
        $this->assertSame('emails', config('email.queue.name'));
    }

    public function test_email_check_command_masks_the_password(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com', 'mail.mailers.smtp.username' => 'sender@example.com', 'mail.mailers.smtp.password' => 'super-secret-app-password', 'mail.from.address' => 'sender@example.com']);

        Artisan::call('email:check');
        $output = Artisan::output();

        $this->assertStringContainsString('smtp.gmail.com', $output);
        $this->assertStringContainsString(EmailLogSanitizer::MASK, $output);
        $this->assertStringNotContainsString('super-secret-app-password', $output);
    }

    public function test_every_template_renders_html_and_plain_text_with_brand_layout(): void
    {
        $resolver = app(EmailContentResolver::class);
        $templates = $resolver->templates();
        $this->assertContains('notification', $templates);
        $this->assertContains('reset-password', $templates);
        $this->assertGreaterThanOrEqual(15, count($templates));

        foreach ($templates as $template) {
            $context = $resolver->previewContext($template);
            $mail = new FacultyLensMail($template, $resolver->subjectFor($context['type'] ?? 'SYSTEM_ALERT'), $context, 1, $context['type'] ?? null);
            $html = $mail->renderHtml();
            $text = $mail->renderText();

            $this->assertStringContainsString('<!DOCTYPE html>', $html, $template);
            $this->assertStringContainsString('FacultyLens', $html, $template);
            $this->assertStringContainsString('AI-Powered Academic Decision Support', $html, "$template header tagline");
            $this->assertStringContainsString('You are receiving this email because of activity associated with your FacultyLens account.', $html, "$template footer");
            $this->assertStringContainsString('#F7F4EE', $html, "$template cream background");
            $this->assertStringContainsString('#171717', $html, "$template charcoal text");
            $this->assertStringContainsString('<meta name="viewport"', $html, "$template responsive meta");
            $this->assertStringContainsString('role="presentation"', $html, "$template table layout");
            $this->assertStringNotContainsString('fonts.googleapis', $html, "$template must not load web fonts");
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('Undefined', $html, $template);
            $this->assertStringNotContainsString('preferences_url', $html, $template);

            $this->assertGreaterThan(80, strlen($text), "$template plain text has content");
            $this->assertStringContainsString('FacultyLens', $text);
            $this->assertStringNotContainsString('<', $text, "$template plain text has no tags");
            $this->assertStringContainsString('http', $text, "$template plain text carries the link");
        }
    }

    public function test_subjects_follow_the_brand_prefix_and_are_single_line(): void
    {
        $resolver = app(EmailContentResolver::class);
        $this->assertSame('FacultyLens — Assessment Analysis Completed', $resolver->subjectFor('AI_ANALYSIS_COMPLETED'));
        $this->assertSame('FacultyLens — Assessment Analysis Failed', $resolver->subjectFor('AI_ANALYSIS_FAILED'));
        $this->assertSame('FacultyLens — New Assessment Recommendations', $resolver->subjectFor('AI_RECOMMENDATION_CREATED'));
        $this->assertSame('FacultyLens — Rubric Draft Ready', $resolver->subjectFor('RUBRIC_GENERATED'));
        $this->assertSame('FacultyLens — Question Drafts Ready', $resolver->subjectFor('QUESTION_GENERATION_COMPLETED'));
        $this->assertSame('FacultyLens — Collaboration Invitation', $resolver->subjectFor('COLLABORATION_INVITATION'));
        $this->assertSame('FacultyLens — Review Assigned', $resolver->subjectFor('REVIEW_ASSIGNED'));
        $this->assertSame('FacultyLens — Your Report Is Ready', $resolver->subjectFor('REPORT_GENERATED'));
        $this->assertSame('FacultyLens — Learning Outcome Review Recommended', $resolver->subjectFor('LEARNING_GAP_DETECTED'));
        $this->assertSame('FacultyLens — Grading Consistency Review Recommended', $resolver->subjectFor('INTER_GRADER_REVIEW_REQUIRED'));
        $this->assertSame('FacultyLens — Performance Analysis Ready', $resolver->subjectFor('PERFORMANCE_ANALYSIS_COMPLETED'));
        $this->assertSame('FacultyLens — Reset Your Password', $resolver->subjectFor('PASSWORD_RESET'));
        $this->assertSame('FacultyLens — Custom title', $resolver->subjectFor('UNKNOWN_TYPE_X', "Custom\r\ntitle"));
    }

    public function test_mailable_carries_from_header_and_tracing_headers(): void
    {
        config(['mail.from.address' => 'sender@example.com', 'mail.from.name' => 'FacultyLens']);
        Mail::fake();
        $user = $this->faculty();
        $this->enableEmail($user, 'REPORT_GENERATED');

        $this->notify($user);

        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $mail) use ($user) {
            $headers = $mail->headers();

            return $mail->hasTo($user->email)
                && $mail->template === 'report-ready'
                && $mail->hasSubject('FacultyLens — Your Report Is Ready')
                && ($headers->text['X-FacultyLens-Type'] ?? null) === 'REPORT_GENERATED'
                && isset($headers->text['X-FacultyLens-Delivery']);
        });
        $this->assertSame('sender@example.com', config('mail.from.address'));
    }

    public function test_status_endpoint_is_coarse_and_never_exposes_transport_settings(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com', 'mail.mailers.smtp.password' => 'app-password-secret', 'mail.mailers.smtp.username' => 'sender@example.com']);
        $res = $this->actingAs($this->faculty())->getJson('/api/email/status')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.capture_mode', false)
            ->assertJsonPath('data.queue', 'emails')
            ->assertJsonPath('data.horizon', true);

        $body = $res->getContent();
        $this->assertStringNotContainsString('smtp.gmail.com', $body);
        $this->assertStringNotContainsString('app-password-secret', $body);
        $this->assertStringNotContainsString('sender@example.com', $body);
        $this->assertStringNotContainsString('587', $body);
    }

    public function test_preview_endpoint_renders_fake_data_without_sending(): void
    {
        Mail::fake();
        config(['email.preview_enabled' => true]);
        $user = $this->faculty();

        $html = $this->actingAs($user)->get('/api/email/preview/assessment-analysis-completed')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('Assessment analysis completed', $html->getContent());
        $this->assertStringContainsString('View Analysis', $html->getContent());

        $text = $this->actingAs($user)->get('/api/email/preview/reset-password?format=text')->assertOk();
        $this->assertStringContainsString('Reset Password', $text->getContent());
        $this->assertStringContainsString('If you did not request a password reset, you can safely ignore this email.', $text->getContent());

        $this->actingAs($user)->getJson('/api/email/preview/report-ready?format=json')->assertOk()->assertJsonStructure(['data' => ['template', 'subject', 'html', 'text']]);
        $this->actingAs($user)->getJson('/api/email/preview/../../etc/passwd')->assertStatus(404);
        $this->actingAs($user)->getJson('/api/email/preview/does-not-exist')->assertStatus(404);

        Mail::assertNothingSent();
        $this->assertSame(0, \App\Models\EmailDelivery::count());

        config(['email.preview_enabled' => false]);
        $this->actingAs($user)->get('/api/email/preview/report-ready')->assertStatus(404);
    }

    public function test_email_can_be_globally_disabled_without_affecting_in_app(): void
    {
        Mail::fake();
        config(['email.enabled' => false]);
        $user = $this->faculty();
        $this->enableEmail($user, 'REPORT_GENERATED');

        $n = $this->notify($user, 'REPORT_GENERATED', [], ['queue' => false]);

        $this->assertNotNull($n, 'in-app notification still written');
        Mail::assertNothingSent();
        $this->assertSame(0, \App\Models\EmailDelivery::count());
        $this->assertFalse(app(EmailService::class)->enabled());
    }

    /**
     * Opt-in live SMTP check. Sends ONE message through the real configured transport in backend/.env.
     * Run manually: EMAIL_LIVE_TESTING=true EMAIL_LIVE_RECIPIENT=you@example.com php artisan test --filter=live_smtp
     */
    public function test_live_smtp_delivery_when_explicitly_enabled(): void
    {
        if (!filter_var(getenv('EMAIL_LIVE_TESTING') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('EMAIL_LIVE_TESTING is not enabled; live Gmail delivery is only tested manually.');
        }
        $envFile = base_path('.env');
        $this->assertFileExists($envFile);
        $env = \Dotenv\Dotenv::parse((string) file_get_contents($envFile));
        $recipient = getenv('EMAIL_LIVE_RECIPIENT') ?: ($env['MAIL_USERNAME'] ?? null);
        $this->assertNotEmpty($recipient, 'EMAIL_LIVE_RECIPIENT or MAIL_USERNAME must be set');
        $this->assertNotEmpty($env['MAIL_PASSWORD'] ?? null, 'MAIL_PASSWORD must be set in backend/.env for the live test');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $env['MAIL_HOST'] ?? 'smtp.gmail.com',
            'mail.mailers.smtp.port' => (int) ($env['MAIL_PORT'] ?? 587),
            'mail.mailers.smtp.encryption' => $env['MAIL_ENCRYPTION'] ?? 'tls',
            'mail.mailers.smtp.username' => $env['MAIL_USERNAME'] ?? null,
            'mail.mailers.smtp.password' => $env['MAIL_PASSWORD'],
            'mail.from.address' => $env['MAIL_FROM_ADDRESS'] ?? $env['MAIL_USERNAME'],
            'mail.from.name' => trim((string) ($env['MAIL_FROM_NAME'] ?? 'FacultyLens'), '"'),
        ]);

        $admin = $this->admin();
        $delivery = app(EmailService::class)->sendTestEmail($admin, (string) $recipient, true);

        $this->assertSame(\App\Models\EmailDelivery::SENT, $delivery->status, 'Live SMTP send failed: ' . $delivery->error_code . ' ' . $delivery->error_message);
        $this->assertNotNull($delivery->sent_at);
    }
}
