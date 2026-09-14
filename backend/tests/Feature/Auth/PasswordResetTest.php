<?php

namespace Tests\Feature\Auth;

use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Forgot password → Laravel password broker → queued FacultyLens reset e-mail → reset page → new password.
 * Covers enumeration protection, rate limiting, token security (hashed, expiring, single use) and log hygiene.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'OldPassword#2026';
    private const NEW = 'NewPassword#2027';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->user = User::factory()->create(['email' => 'existing@university.edu', 'password' => Hash::make(self::OLD)]);
    }

    // ------------------------------------------------------------------ forgot

    public function test_forgot_password_validates_the_email_field(): void
    {
        $this->postJson('/api/auth/forgot-password', [])->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors(['email']);
        Mail::assertNothingSent();
    }

    public function test_forgot_password_for_existing_account_creates_hashed_token_and_queues_reset_email(): void
    {
        $res = $this->postJson('/api/auth/forgot-password', ['email' => 'Existing@University.edu'])->assertOk();
        $res->assertJsonPath('status', 'success')->assertJsonPath('message', 'If an account exists for this email address, a password reset link has been sent.');

        $row = DB::table('password_reset_tokens')->where('email', 'existing@university.edu')->first();
        $this->assertNotNull($row, 'broker stored a token');
        $this->assertNotEquals('', $row->token);
        $this->assertTrue(str_starts_with($row->token, '$2y$'), 'token is stored hashed');

        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $mail) use ($row) {
            $url = $mail->context['reset_url'];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $mail->hasTo('existing@university.edu')
                && $mail->template === 'reset-password'
                && $mail->subjectLine === 'FacultyLens — Reset Your Password'
                && str_starts_with($url, rtrim(config('email.frontend_url'), '/') . '/reset-password?')
                && $query['email'] === 'existing@university.edu'
                && strlen($query['token']) >= 40
                && $query['token'] !== $row->token
                && Hash::check($query['token'], $row->token)
                && str_contains($mail->renderHtml(), 'If you did not request a password reset, you can safely ignore this email.')
                && str_contains($mail->renderHtml(), 'never share this reset link')
                && str_contains($mail->renderText(), 'Reset Password: ' . $url);
        });

        $delivery = EmailDelivery::first();
        $this->assertSame('PASSWORD_RESET', $delivery->type);
        $this->assertSame(EmailDelivery::SENT, $delivery->status);
        $this->assertSame($this->user->id, $delivery->user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_RESET_REQUESTED', 'entity_id' => $this->user->id]);
    }

    public function test_forgot_password_for_unknown_account_returns_the_same_response_and_sends_nothing(): void
    {
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();
        Mail::assertSent(FacultyLensMail::class, 1);

        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nonexistent@university.edu'])->assertOk();

        $this->assertSame($known->json(), $unknown->json(), 'account enumeration: identical bodies');
        $this->assertSame($known->status(), $unknown->status());
        Mail::assertSent(FacultyLensMail::class, 1);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'nonexistent@university.edu')->count());
        $this->assertSame(1, EmailDelivery::count());
    }

    public function test_forgot_password_broker_throttle_does_not_leak_account_existence(): void
    {
        $first = $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();
        $second = $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();

        $this->assertSame($first->json(), $second->json());
        Mail::assertSent(FacultyLensMail::class, 1, 'broker throttle (auth.passwords.users.throttle) suppresses the second e-mail');
    }

    public function test_forgot_password_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "user{$i}@university.edu"])->assertOk();
        }
        $this->postJson('/api/auth/forgot-password', ['email' => 'user9@university.edu'])->assertStatus(429)->assertJsonPath('status', 'error');
    }

    public function test_reset_email_is_queued_on_the_emails_queue(): void
    {
        Queue::fake();
        $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();

        Queue::assertPushed(\App\Jobs\SendFacultyLensEmailJob::class, fn ($job) => $job->queue === 'emails' && $job->template === 'reset-password' && $job->afterCommit === true);
        $this->assertSame(EmailDelivery::PENDING, EmailDelivery::first()->status);
    }

    public function test_reset_email_uses_configured_frontend_url_not_request_input(): void
    {
        config(['email.frontend_url' => 'https://facultylens.example.edu']);
        $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu', 'redirect' => 'https://evil.example', 'frontend_url' => 'https://evil.example'])->assertOk();

        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => str_starts_with($m->context['reset_url'], 'https://facultylens.example.edu/reset-password?') && !str_contains($m->context['reset_url'], 'evil'));
    }

    // ------------------------------------------------------------------- reset

    public function test_reset_password_with_valid_token_sets_new_password_and_consumes_token(): void
    {
        $token = Password::broker()->createToken($this->user);

        $res = $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk();
        $res->assertJsonPath('status', 'success');
        $this->assertStringContainsString('You can now sign in with your new password.', $res->json('message'));

        $this->user->refresh();
        $this->assertTrue(Hash::check(self::NEW, $this->user->password), 'new password hashed & stored');
        $this->assertFalse(Hash::check(self::OLD, $this->user->password), 'old password no longer works');
        $this->assertNotSame(self::NEW, $this->user->password, 'never plaintext');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'existing@university.edu')->count(), 'token consumed');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_RESET_COMPLETED', 'entity_id' => $this->user->id]);
        $this->assertGuest('web');

        // reuse is rejected
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'existing@university.edu', 'password' => 'Another#2028x', 'password_confirmation' => 'Another#2028x'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
    }

    public function test_login_works_with_new_password_and_fails_with_old_after_reset(): void
    {
        $token = Password::broker()->createToken($this->user);
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'existing@university.edu', 'password' => self::OLD])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'existing@university.edu', 'password' => self::NEW])->assertOk()->assertJsonPath('user.email', 'existing@university.edu');
    }

    public function test_reset_rejects_invalid_expired_and_mismatched_tokens_with_one_generic_message(): void
    {
        $token = Password::broker()->createToken($this->user);
        $payload = fn (array $o = []) => array_replace(['token' => $token, 'email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW], $o);

        // invalid token
        $this->postJson('/api/auth/reset-password', $payload(['token' => 'not-the-token']))->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN')->assertJsonPath('message', 'This password reset link is invalid or has expired.');
        // wrong e-mail for a real token
        $other = User::factory()->create(['email' => 'other@university.edu']);
        $this->postJson('/api/auth/reset-password', $payload(['email' => 'other@university.edu']))->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
        // unknown e-mail — same message as an invalid token
        $this->postJson('/api/auth/reset-password', $payload(['email' => 'ghost@university.edu']))->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');
        // expired token
        DB::table('password_reset_tokens')->where('email', 'existing@university.edu')->update(['created_at' => now()->subMinutes((int) config('auth.passwords.users.expire') + 1)]);
        $this->postJson('/api/auth/reset-password', $payload())->assertStatus(422)->assertJsonPath('code', 'INVALID_RESET_TOKEN');

        $this->assertTrue(Hash::check(self::OLD, $this->user->fresh()->password), 'password unchanged');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_RESET_FAILED']);
    }

    public function test_reset_validates_password_policy_and_confirmation(): void
    {
        $token = Password::broker()->createToken($this->user);
        $base = ['token' => $token, 'email' => 'existing@university.edu'];

        $this->postJson('/api/auth/reset-password', $base + ['password' => self::NEW, 'password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/reset-password', $base + ['password' => 'short1', 'password_confirmation' => 'short1'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/reset-password', $base + ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson('/api/auth/reset-password', ['email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(422)->assertJsonValidationErrors(['token']);

        $this->assertTrue(Hash::check(self::OLD, $this->user->fresh()->password));
        $this->assertSame(1, DB::table('password_reset_tokens')->count(), 'token still usable after validation errors');
    }

    public function test_reset_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', ['token' => 'x', 'email' => "u{$i}@university.edu", 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(422);
        }
        $this->postJson('/api/auth/reset-password', ['token' => 'x', 'email' => 'u9@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertStatus(429);
    }

    public function test_successful_reset_invalidates_api_tokens_and_raises_a_security_alert(): void
    {
        $this->user->createToken('device-a');
        $this->user->createToken('device-b');
        $this->assertSame(2, $this->user->tokens()->count());
        $token = Password::broker()->createToken($this->user);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk();

        $this->assertSame(0, $this->user->tokens()->count(), 'other sessions/tokens invalidated');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->user->id, 'type' => 'SECURITY_ALERT']);
        Mail::assertSent(FacultyLensMail::class, fn (FacultyLensMail $m) => $m->template === 'security-alert' && $m->hasTo('existing@university.edu'));
    }

    // ---------------------------------------------------------------- hygiene

    public function test_tokens_and_passwords_never_appear_in_logs_audit_rows_or_responses(): void
    {
        Log::spy();
        $forgot = $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();
        $token = null;
        Mail::assertSent(FacultyLensMail::class, function (FacultyLensMail $m) use (&$token) {
            parse_str((string) parse_url($m->context['reset_url'], PHP_URL_QUERY), $q);
            $token = $q['token'];

            return true;
        });
        $reset = $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'existing@university.edu', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk();

        foreach ([$forgot->getContent(), $reset->getContent()] as $body) {
            $this->assertStringNotContainsString($token, $body);
            $this->assertStringNotContainsString(self::NEW, $body);
        }
        foreach (DB::table('audit_logs')->get() as $row) {
            $blob = json_encode($row);
            $this->assertStringNotContainsString($token, $blob);
            $this->assertStringNotContainsString(self::NEW, $blob);
            $this->assertStringNotContainsString(self::OLD, $blob);
        }
        foreach (DB::table('email_deliveries')->get() as $row) {
            $this->assertStringNotContainsString($token, json_encode($row));
        }
        foreach (['info', 'warning', 'error', 'debug', 'notice'] as $level) {
            Log::shouldNotHaveReceived($level, function ($message, $context = []) use ($token) {
                $blob = $message . json_encode($context);

                return str_contains($blob, $token) || str_contains($blob, self::NEW) || str_contains($blob, self::OLD);
            });
        }
    }

    public function test_reset_email_ignores_notification_preferences_and_is_never_deduplicated_across_requests(): void
    {
        \App\Models\NotificationPreference::updateOrCreate(['user_id' => $this->user->id, 'notification_type' => 'SECURITY_ALERT'], ['in_app_enabled' => false, 'email_enabled' => false]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();
        DB::table('password_reset_tokens')->delete(); // broker throttle window bypass for the test
        $this->postJson('/api/auth/forgot-password', ['email' => 'existing@university.edu'])->assertOk();

        Mail::assertSent(FacultyLensMail::class, 2);
        $this->assertSame(2, EmailDelivery::where('type', 'PASSWORD_RESET')->count());
    }
}
