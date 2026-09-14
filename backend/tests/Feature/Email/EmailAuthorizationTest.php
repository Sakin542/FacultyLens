<?php

namespace Tests\Feature\Email;

use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Authorization boundaries of the e-mail endpoints: authentication required everywhere, faculty can only test
 * against their own address, delivery logs are private, preferences are per caller.
 */
class EmailAuthorizationTest extends TestCase
{
    use RefreshDatabase, EmailTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['email.test_endpoint_enabled' => true, 'email.preview_enabled' => true]);
    }

    public function test_all_email_endpoints_require_authentication(): void
    {
        $this->getJson('/api/email/status')->assertStatus(401);
        $this->postJson('/api/email/test', ['recipient' => 'a@b.co'])->assertStatus(401);
        $this->getJson('/api/email/deliveries')->assertStatus(401);
        $this->getJson('/api/email/deliveries/1')->assertStatus(401);
        $this->getJson('/api/email/preview/notification')->assertStatus(401);
        $this->getJson('/api/notification-preferences')->assertStatus(401);
        Mail::assertNothingSent();
    }

    public function test_faculty_can_only_send_a_test_email_to_their_own_address(): void
    {
        $user = $this->faculty(['email' => 'me@university.edu']);

        $this->actingAs($user)->postJson('/api/email/test', ['recipient' => 'victim@example.com'])->assertStatus(403);
        Mail::assertNothingSent();

        $res = $this->actingAs($user)->postJson('/api/email/test', ['recipient' => 'ME@university.edu'])->assertStatus(202);
        $res->assertJsonPath('data.status', EmailDelivery::SENT)->assertJsonPath('data.type', 'TEST_EMAIL');
        $this->assertSame('me*@university.edu', $res->json('data.recipient'), 'recipient masked in API');
        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->hasTo('me@university.edu') && $m->template === 'test-email');

        // default recipient is the caller
        $this->actingAs($user)->postJson('/api/email/test')->assertStatus(202);
        Mail::assertSent(FacultyLensMail::class, 2);
    }

    public function test_admin_may_target_any_valid_address_but_never_an_invalid_one(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/email/test', ['recipient' => 'ops@university.edu'])->assertStatus(202);
        $this->actingAs($admin)->postJson('/api/email/test', ['recipient' => 'not-an-email'])->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/email/test', ['recipient' => "a@b.co\r\nBcc: x@y.z"])->assertStatus(422);
        Mail::assertSent(FacultyLensMail::class, 1);
    }

    public function test_test_endpoint_is_disabled_unless_enabled_and_is_rate_limited(): void
    {
        config(['email.test_endpoint_enabled' => false]);
        $this->actingAs($this->faculty())->postJson('/api/email/test')->assertStatus(403);
        Mail::assertNothingSent();

        config(['email.test_endpoint_enabled' => true, 'email.test_rate_limit_per_hour' => 2]);
        $user = $this->faculty();
        $this->actingAs($user)->postJson('/api/email/test')->assertStatus(202);
        $this->actingAs($user)->postJson('/api/email/test')->assertStatus(202);
        $this->actingAs($user)->postJson('/api/email/test')->assertStatus(429);
    }

    public function test_test_endpoint_never_accepts_or_returns_smtp_settings(): void
    {
        config(['mail.mailers.smtp.password' => 'app-password-secret', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        $user = $this->faculty();

        $res = $this->actingAs($user)->postJson('/api/email/test', ['recipient' => $user->email, 'host' => 'evil.example', 'username' => 'x', 'password' => 'y', 'port' => 25])->assertStatus(202);

        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'), 'request cannot alter transport');
        $this->assertStringNotContainsString('app-password-secret', $res->getContent());
        $this->assertStringNotContainsString('smtp.gmail.com', $res->getContent());
        $this->assertArrayNotHasKey('idempotency_key', $res->json('data'));
    }

    public function test_delivery_log_is_scoped_to_the_caller(): void
    {
        $a = $this->faculty();
        $b = $this->faculty();
        $da = EmailDelivery::create(['user_id' => $a->id, 'type' => 'REPORT_GENERATED', 'template' => 'report-ready', 'recipient' => $a->email, 'subject' => 'A', 'status' => 'SENT', 'idempotency_key' => 'a']);
        $db = EmailDelivery::create(['user_id' => $b->id, 'type' => 'REPORT_GENERATED', 'template' => 'report-ready', 'recipient' => $b->email, 'subject' => 'B', 'status' => 'SENT', 'idempotency_key' => 'b']);

        $list = $this->actingAs($a)->getJson('/api/email/deliveries')->assertOk();
        $this->assertSame([$da->id], array_column($list->json('data'), 'id'));
        $this->actingAs($a)->getJson('/api/email/deliveries/' . $da->id)->assertOk()->assertJsonPath('data.subject', 'A');
        $this->actingAs($a)->getJson('/api/email/deliveries/' . $db->id)->assertStatus(404);
        $this->actingAs($a)->getJson('/api/email/deliveries?status=NOPE')->assertStatus(422);
    }

    public function test_user_a_cannot_modify_user_b_email_preferences(): void
    {
        $a = $this->faculty();
        $b = $this->faculty();

        $this->actingAs($a)->putJson('/api/notification-preferences', ['user_id' => $b->id, 'preferences' => [
            ['notification_type' => 'REPORT_GENERATED', 'in_app_enabled' => true, 'email_enabled' => false],
        ]])->assertOk();
        $this->actingAs($a)->patchJson('/api/notification-preferences/REPORT_GENERATED?user_id=' . $b->id, ['in_app_enabled' => true, 'email_enabled' => false, 'user_id' => $b->id])->assertOk();

        $this->assertSame(0, NotificationPreference::where('user_id', $b->id)->count());
        $this->assertFalse(NotificationPreference::emailEnabled($a->id, 'REPORT_GENERATED'));
        $this->assertTrue(NotificationPreference::emailEnabled($b->id, 'REPORT_GENERATED'));
    }

    public function test_preview_is_authenticated_and_unavailable_when_disabled(): void
    {
        config(['email.preview_enabled' => false]);
        $this->actingAs($this->faculty())->get('/api/email/preview/notification')->assertStatus(404);
        config(['email.preview_enabled' => true]);
        $this->actingAs($this->faculty())->get('/api/email/preview/notification')->assertOk();
    }
}
