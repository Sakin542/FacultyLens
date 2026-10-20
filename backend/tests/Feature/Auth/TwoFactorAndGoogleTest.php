<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwoFactorAndGoogleTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ TOTP algorithm

    public function test_totp_matches_the_rfc_6238_reference_vector(): void
    {
        // RFC 6238 SHA-1 secret "12345678901234567890"; T=59s → step 1 → 94287082 (6-digit form: 287082)
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', app(TwoFactorService::class)->codeAt($secret, 1));
        // T=1111111109 → 07081804
        $this->assertSame('081804', app(TwoFactorService::class)->codeAt($secret, intdiv(1111111109, 30)));
    }

    // ------------------------------------------------------------------ setup flow

    public function test_user_can_enable_confirm_and_disable_two_factor(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);
        $totp = app(TwoFactorService::class);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/two-factor/enable', ['password' => 'wrong'])->assertStatus(422);

        $start = $this->actingAs($user, 'sanctum')->postJson('/api/auth/two-factor/enable', ['password' => 'Password123!'])
            ->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/', $start['otpauth_url']);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);

        $codes = $this->actingAs($user, 'sanctum')->postJson('/api/auth/two-factor/confirm', ['code' => $totp->currentCode($start['secret'])])
            ->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        // Secret is encrypted at rest
        $this->assertStringNotContainsString($start['secret'], (string) \DB::table('users')->where('id', $user->id)->value('two_factor_secret'));

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/auth/two-factor')
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_remaining', 8);

        $this->actingAs($user->fresh(), 'sanctum')->deleteJson('/api/auth/two-factor', ['password' => 'Password123!'])->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    // ------------------------------------------------------------------ sign-in with 2FA

    public function test_login_requires_a_valid_code_when_two_factor_is_on(): void
    {
        $totp = app(TwoFactorService::class);
        $secret = $totp->generateSecret();
        $user = $this->userWithTwoFactor($secret, ['AAAAA-BBBBB']);

        $challenge = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertOk()->assertJsonPath('status', 'two_factor_required')->json('challenge_token');
        $this->assertGuest('web');

        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => '123456'])->assertStatus(422);
        $this->assertGuest('web');

        $code = $totp->currentCode($secret);
        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertAuthenticatedAs($user, 'web');

        // The challenge is single-use and the same code cannot be replayed
        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $code])->assertStatus(401);
        $this->assertFalse($totp->verify($secret, $code, $user));
    }

    public function test_recovery_code_signs_in_once(): void
    {
        $user = $this->userWithTwoFactor(app(TwoFactorService::class)->generateSecret(), ['AAAAA-BBBBB', 'CCCCC-DDDDD']);

        $first = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->json('challenge_token');
        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $first, 'recovery_code' => 'aaaaa-bbbbb'])->assertOk();
        $this->assertSame(['CCCCC-DDDDD'], $user->fresh()->two_factor_recovery_codes);

        auth()->guard('web')->logout();
        $second = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->json('challenge_token');
        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $second, 'recovery_code' => 'AAAAA-BBBBB'])->assertStatus(422);
    }

    public function test_five_wrong_codes_cancel_the_challenge(): void
    {
        $user = $this->userWithTwoFactor(app(TwoFactorService::class)->generateSecret(), []);
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!'])->json('challenge_token');

        foreach (range(1, 4) as $_) {
            $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $token, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/auth/two-factor/challenge', ['challenge_token' => $token, 'code' => '000000'])->assertStatus(401);
    }

    // ------------------------------------------------------------------ Google sign-in

    public function test_google_sign_in_creates_and_links_faculty_accounts(): void
    {
        config(['admin.google.client_id' => 'client-123.apps.googleusercontent.com', 'admin.google.allowed_domains' => ['aust.edu']]);
        $this->fakeGoogle('new.faculty@aust.edu', 'sub-1');

        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertOk()->assertJsonPath('user.email', 'new.faculty@aust.edu');
        $created = User::where('email', 'new.faculty@aust.edu')->firstOrFail();
        $this->assertSame('sub-1', $created->google_id);
        $this->assertSame('FACULTY', $created->role ?? 'FACULTY');
    }

    public function test_google_sign_in_enforces_audience_domain_and_admin_rules(): void
    {
        config(['admin.google.client_id' => 'client-123.apps.googleusercontent.com', 'admin.google.allowed_domains' => ['aust.edu'], 'admin.email' => 'boss@aust.edu']);

        $this->fakeGoogle('someone@gmail.com', 'sub-2');
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertStatus(401);

        $this->fakeGoogle('x@aust.edu', 'sub-3', aud: 'another-app');
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertStatus(401);

        User::factory()->create(['email' => 'boss@aust.edu', 'role' => 'ADMIN']);
        $this->fakeGoogle('boss@aust.edu', 'sub-4');
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertStatus(403);
        $this->assertGuest('web');
    }

    public function test_google_sign_in_is_rejected_when_not_configured(): void
    {
        $this->postJson('/api/auth/google', ['credential' => 'id-token'])->assertStatus(401);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('data.google_client_id', null);
    }

    protected function userWithTwoFactor(string $secret, array $recovery): User
    {
        $user = User::factory()->create(['password' => 'Password123!']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $recovery, 'two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    /** @var array<string, string> claims the faked tokeninfo endpoint returns next */
    protected array $googleClaims = [];

    protected bool $googleFaked = false;

    protected function fakeGoogle(string $email, string $sub, string $aud = 'client-123.apps.googleusercontent.com'): void
    {
        $this->googleClaims = [
            'aud' => $aud, 'iss' => 'https://accounts.google.com', 'sub' => $sub, 'email' => $email,
            'email_verified' => 'true', 'name' => 'Google User', 'exp' => (string) (time() + 600),
        ];
        if (!$this->googleFaked) {
            // Http::fake keeps the first matching stub, so one closure serves whatever claims are current
            Http::fake(['oauth2.googleapis.com/*' => fn () => Http::response($this->googleClaims)]);
            $this->googleFaked = true;
        }
    }
}
