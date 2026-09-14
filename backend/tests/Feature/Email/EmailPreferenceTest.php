<?php

namespace Tests\Feature\Email;

use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Models\NotificationPreference;
use App\Notifications\NotificationType;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * notification_preferences.email_enabled drives the e-mail channel: NULL → category default, explicit true/false,
 * SECURITY always e-mailed, "never e-mail" types suppressed, and the API cannot switch off mandatory e-mails.
 */
class EmailPreferenceTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['notifications.queue.enabled' => false]);
    }

    public function test_unset_preference_uses_the_category_default(): void
    {
        $user = $this->faculty();
        config(['email.default_email_enabled.REPORT' => true, 'email.default_email_enabled.ASSESSMENT' => false]);

        $this->assertTrue(NotificationPreference::emailEnabled($user->id, NotificationType::REPORT_GENERATED));
        $this->assertFalse(NotificationPreference::emailEnabled($user->id, NotificationType::ASSESSMENT_VERSION_CREATED));

        $this->notify($user);
        Mail::assertSent(FacultyLensMail::class, 1);

        $this->notify($user, NotificationType::ASSESSMENT_VERSION_CREATED, ['title' => 'Version created', 'message' => 'v1', 'entity_type' => 'assessment_version', 'entity_id' => 7, 'data' => []]);
        Mail::assertSent(FacultyLensMail::class, 1);
    }

    public function test_explicit_false_suppresses_email_but_not_in_app(): void
    {
        $user = $this->faculty();
        $this->enableEmail($user, NotificationType::REPORT_GENERATED, false);

        $this->assertNotNull($this->notify($user));
        Mail::assertNothingSent();
        $this->assertSame(0, EmailDelivery::count());
    }

    public function test_security_alerts_are_emailed_even_when_the_user_opted_out(): void
    {
        $user = $this->faculty();
        NotificationPreference::updateOrCreate(['user_id' => $user->id, 'notification_type' => NotificationType::SECURITY_ALERT], ['in_app_enabled' => false, 'email_enabled' => false]);

        $this->notify($user, NotificationType::SECURITY_ALERT, ['title' => 'Multiple failed sign-in attempts', 'message' => 'There were 5 failed sign-in attempts on your account.', 'action_url' => '/settings', 'entity_type' => 'user', 'entity_id' => $user->id, 'dedupe_key' => 'SECURITY_ALERT:x', 'data' => ['event' => 'FAILED_LOGIN_ATTEMPTS']]);

        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'security-alert' && $m->hasTo($user->email));
        $this->assertTrue(app(EmailService::class)->isSecurity(NotificationType::SECURITY_ALERT));
    }

    public function test_never_email_types_are_not_sent_even_when_enabled(): void
    {
        $user = $this->faculty();
        config(['email.never_email_types' => ['COLLABORATION_ROLE_CHANGED']]);
        $this->enableEmail($user, NotificationType::COLLABORATION_ROLE_CHANGED, true);

        $this->notify($user, NotificationType::COLLABORATION_ROLE_CHANGED, ['title' => 'Role changed', 'message' => 'x', 'entity_type' => 'course', 'entity_id' => 3, 'data' => []]);

        Mail::assertNothingSent();
        $this->assertFalse(app(EmailService::class)->shouldEmail($user->id, NotificationType::COLLABORATION_ROLE_CHANGED));
    }

    public function test_preferences_api_persists_email_flag_and_reports_effective_matrix(): void
    {
        $user = $this->faculty();

        $res = $this->actingAs($user)->putJson('/api/notification-preferences', ['preferences' => [
            ['notification_type' => 'REPORT_GENERATED', 'in_app_enabled' => true, 'email_enabled' => false],
            ['notification_type' => 'AI_ANALYSIS_COMPLETED', 'in_app_enabled' => false, 'email_enabled' => true],
        ]])->assertOk();

        $prefs = collect($res->json('data.preferences'));
        $this->assertFalse($prefs->firstWhere('notification_type', 'REPORT_GENERATED')['email_enabled']);
        $this->assertTrue($prefs->firstWhere('notification_type', 'AI_ANALYSIS_COMPLETED')['email_enabled']);
        $this->assertFalse($prefs->firstWhere('notification_type', 'AI_ANALYSIS_COMPLETED')['in_app_enabled']);
        $this->assertTrue($prefs->firstWhere('notification_type', 'SECURITY_ALERT')['email_mandatory']);
        $this->assertFalse($prefs->firstWhere('notification_type', 'COLLABORATION_ROLE_CHANGED')['email_available']);

        $this->assertFalse(NotificationPreference::emailEnabled($user->id, 'REPORT_GENERATED'));
        $this->assertTrue(NotificationPreference::emailEnabled($user->id, 'AI_ANALYSIS_COMPLETED'));

        $index = $this->actingAs($user)->getJson('/api/notification-preferences')->assertOk();
        $index->assertJsonPath('data.email_mandatory_categories', ['SECURITY']);
        $this->assertIsBool($index->json('data.email_available'));
    }

    public function test_api_cannot_disable_mandatory_security_email(): void
    {
        $user = $this->faculty();

        $res = $this->actingAs($user)->putJson('/api/notification-preferences', ['preferences' => [
            ['notification_type' => 'SECURITY_ALERT', 'in_app_enabled' => false, 'email_enabled' => false],
        ]])->assertOk();

        $security = collect($res->json('data.preferences'))->firstWhere('notification_type', 'SECURITY_ALERT');
        $this->assertTrue($security['in_app_enabled']);
        $this->assertTrue($security['email_enabled']);
        $this->assertTrue(NotificationPreference::emailEnabled($user->id, 'SECURITY_ALERT'));
    }

    public function test_patch_single_type_email_flag(): void
    {
        $user = $this->faculty();
        $this->actingAs($user)->patchJson('/api/notification-preferences/report_generated', ['in_app_enabled' => true, 'email_enabled' => false])
            ->assertOk()->assertJsonPath('data.email_enabled', false)->assertJsonPath('data.email_mandatory', false);
        $this->assertFalse(NotificationPreference::emailEnabled($user->id, 'REPORT_GENERATED'));
    }

    public function test_password_reset_email_ignores_notification_preferences(): void
    {
        $user = $this->faculty();
        foreach (NotificationType::all() as $type) {
            NotificationPreference::updateOrCreate(['user_id' => $user->id, 'notification_type' => $type], ['in_app_enabled' => false, 'email_enabled' => false]);
        }
        config(['email.rate_limit.per_user_per_hour' => 0]);

        $delivery = app(EmailService::class)->sendPasswordReset($user, 'token-abc');

        $this->assertSame(EmailDelivery::SENT, $delivery->status);
        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'reset-password' && $m->hasTo($user->email));
    }
}
