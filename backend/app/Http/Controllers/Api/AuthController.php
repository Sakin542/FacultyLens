<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new faculty user
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => trim($request->name),
            'email' => strtolower(trim($request->email)),
            'department' => trim($request->department),
            'designation' => trim($request->designation),
            'password' => Hash::make($request->password),
        ]);

        // Authenticate the registered user using Sanctum session
        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful',
            'user' => $user->profilePayload(),
        ], 201);
    }

    /**
     * Authenticate faculty member and establish session
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->attemptLogin($request, adminOnly: false);
    }

    /**
     * Admin panel sign-in: identical credential handling, but only the configured administrator account is accepted.
     * Any other valid account is refused without starting a session.
     */
    public function adminLogin(LoginRequest $request): JsonResponse
    {
        return $this->attemptLogin($request, adminOnly: true);
    }

    /**
     * Complete a sign-in that was paused for two-factor verification (TOTP code or single-use recovery code).
     */
    public function twoFactorChallenge(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $key = $this->challengeKey($validated['challenge_token']);
        $pending = Cache::get($key);
        $user = is_array($pending) ? User::find($pending['user_id'] ?? 0) : null;
        if (!$user || !$user->hasTwoFactorEnabled() || !$user->isActive()) {
            Cache::forget($key);

            return response()->json(['status' => 'error', 'message' => 'This sign-in attempt has expired. Please sign in again.'], 401);
        }

        $valid = !empty($validated['code'])
            ? $twoFactor->verify((string) $user->two_factor_secret, (string) $validated['code'], $user)
            : $twoFactor->useRecoveryCode($user, (string) $validated['recovery_code']);

        if (!$valid) {
            $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            if ($pending['attempts'] >= 5) {
                Cache::forget($key);
                $this->recordFailedLogin($user->email);

                return response()->json(['status' => 'error', 'message' => 'Too many incorrect codes. Please sign in again.'], 401);
            }
            Cache::put($key, $pending, now()->addMinutes((int) config('admin.two_factor.challenge_ttl_minutes', 5)));

            return response()->json([
                'status' => 'error',
                'message' => 'The verification code is incorrect.',
                'errors' => ['code' => ['The verification code is incorrect.']],
            ], 422);
        }

        Cache::forget($key);
        if (empty($validated['code'])) {
            app(\App\Services\AuditLogService::class)->log('TWO_FACTOR_RECOVERY_CODE_USED', $user, $user->id, null, $user);
        }

        return $this->completeLogin($request, $user, (bool) ($pending['remember'] ?? false));
    }

    /**
     * "Sign in with Google": verify the ID token, then sign in the matching account (linked by Google subject or by
     * e-mail) or create a FACULTY account when self-registration is on. The administrator must use a password.
     */
    public function googleLogin(Request $request, \App\Services\Auth\GoogleIdTokenVerifier $google, \App\Services\Admin\FeatureFlagService $features): JsonResponse
    {
        $validated = $request->validate(['credential' => ['required', 'string', 'max:4096']]);

        try {
            $identity = $google->verify($validated['credential']);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 401);
        }

        $user = User::query()->where('google_id', $identity['sub'])->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [$identity['email']])->first();

        if ($user && $user->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'The administrator account must sign in with its password.'], 403);
        }

        if (!$user) {
            if (!$features->enabled('registration')) {
                return response()->json(['status' => 'error', 'message' => 'No FacultyLens account uses this Google address, and new registrations are closed.'], 403);
            }
            $user = User::create([
                'name' => $identity['name'],
                'email' => $identity['email'],
                // Unusable random password: the account signs in with Google (or after a password reset)
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]);
            $user->forceFill(['google_id' => $identity['sub'], 'email_verified_at' => now()])->save();
            app(\App\Services\AuditLogService::class)->log('GOOGLE_ACCOUNT_CREATED', $user, $user->id, null, $user);
        } elseif (empty($user->google_id)) {
            $user->forceFill(['google_id' => $identity['sub']])->save();
            app(\App\Services\AuditLogService::class)->log('GOOGLE_ACCOUNT_LINKED', $user, $user->id, null, $user);
        }

        if (!$user->isActive()) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'This account has been deactivated. Contact your administrator.',
            ], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->twoFactorRequired($user, false);
        }

        return $this->completeLogin($request, $user, false);
    }

    protected function twoFactorRequired(User $user, bool $remember): JsonResponse
    {
        $token = bin2hex(random_bytes(32));
        Cache::put($this->challengeKey($token), [
            'user_id' => $user->id,
            'remember' => $remember,
            'attempts' => 0,
        ], now()->addMinutes((int) config('admin.two_factor.challenge_ttl_minutes', 5)));

        return response()->json([
            'status' => 'two_factor_required',
            'message' => 'Enter the 6-digit code from your authenticator app.',
            'two_factor' => true,
            'challenge_token' => $token,
        ]);
    }

    protected function attemptLogin(LoginRequest $request, bool $adminOnly): JsonResponse
    {
        $email = strtolower(trim($request->email));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user || !Hash::check((string) $request->password, (string) $user->password)) {
            $this->recordFailedLogin($email);
            // Security center fact: one row per failed attempt (never the submitted e-mail or password)
            app(\App\Services\AuditLogService::class)->log('AUTH_LOGIN_FAILED', 'User', $user?->id, ['known_account' => $user !== null, 'admin_page' => $adminOnly], $user);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password',
            ], 401);
        }

        if ($adminOnly && !$user->isAdmin()) {
            app(\App\Services\AuditLogService::class)->log('ADMIN_LOGIN_REFUSED', $user, $user->id, null, $user);

            return response()->json([
                'status' => 'error',
                'message' => 'This sign-in page is for the FacultyLens administrator only.',
            ], 403);
        }

        if (!$user->isActive()) {
            return response()->json([
                'status' => 'error',
                'code' => 'ACCOUNT_DEACTIVATED',
                'message' => 'This account has been deactivated. Contact your administrator.',
            ], 403);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $this->twoFactorRequired($user, $request->boolean('remember', false));
        }

        return $this->completeLogin($request, $user, $request->boolean('remember', false));
    }

    /** Start the session for a fully verified user (password, and 2FA when enabled, already checked). */
    protected function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        Cache::forget($this->failedLoginKey($user->email));

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => $user->profilePayload(),
        ]);
    }

    protected function challengeKey(string $token): string
    {
        return '2fa:challenge:'.hash('sha256', $token);
    }

    /**
     * Get the currently authenticated faculty member
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'user' => $user->profilePayload(),
        ]);
    }

    /**
     * Session probe used on app boot. Always 200 — an anonymous visitor is a normal state, not an error, so the
     * browser console stays clean and no "session expired" handling is triggered.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');

        return response()->json([
            'status' => 'success',
            'authenticated' => $user !== null,
            'user' => $user?->profilePayload(),
        ]);
    }

    /**
     * Update user profile with strict protection against privilege escalation.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'designation' => ['sometimes', 'string', 'max:255'],
        ]);

        // Roles are never editable from the profile (any `role` field is ignored); the admin panel manages them.

        // Email is the institutional identity and is never editable here (silently ignored if sent).
        $user->fill($validated);
        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed !== []) {
            app(\App\Services\AuditLogService::class)->log('PROFILE_UPDATED', $user, $user->id, ['fields' => $changed], $user);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully.',
            'user' => $user->profilePayload(),
        ]);
    }

    /**
     * Change the authenticated user's password (requires the current password).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed', 'different:current_password'],
        ], [
            'password.different' => 'The new password must be different from the current password.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Other sessions/tokens are invalidated; the current session stays signed in.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
        app(\App\Services\AuditLogService::class)->log('PASSWORD_CHANGED', $user, $user->id, null, $user);

        // STEP 47: account-safety notice (mandatory SECURITY category; no secrets or technical detail in the payload)
        event(new \App\Events\SecurityAlertRaised(
            $user,
            'Your password was changed',
            'The password for your FacultyLens account was changed just now. If you did not make this change, reset your password and contact your administrator.',
            ['event' => 'PASSWORD_CHANGED'],
            'SECURITY_ALERT:user:' . $user->id . ':password_changed:' . now()->timestamp,
        ));

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * Log the faculty member out and invalidate session, tokens, and cookies
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user('sanctum') ?? $request->user('web');
        if ($user) {
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
            $user->setRememberToken(null);
            $user->saveQuietly();
        }

        try {
            Auth::guard('sanctum')->forgetUser();
        } catch (\Throwable) {
            // Guard may not be initialized
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Auth::forgetGuards();
        $request->setUserResolver(static fn () => null);

        $sessionCookieName = (string) config('session.cookie');
        $recallerName = Auth::guard('web')->getRecallerName();

        $response = response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
        ]);

        if ($sessionCookieName !== '') {
            $response->withCookie(Cookie::forget($sessionCookieName));
        }
        if ($recallerName !== '') {
            $response->withCookie(Cookie::forget($recallerName));
        }

        return $response;
    }

    // ------------------------------------------------------------ STEP 47: security notifications

    protected function failedLoginKey(string $email): string
    {
        return 'auth:failed-logins:' . sha1(strtolower($email));
    }

    /**
     * Counts failed attempts per account within a rolling window; once the threshold is reached the account owner
     * receives one SECURITY_ALERT per window. The response to the caller is identical whether or not the account exists.
     */
    protected function recordFailedLogin(string $email): void
    {
        try {
            $threshold = max(1, (int) config('notifications.security.failed_login_threshold', 5));
            $window = max(1, (int) config('notifications.security.failed_login_window_minutes', 15));
            $key = $this->failedLoginKey($email);
            $cache = \Illuminate\Support\Facades\Cache::store();
            $cache->add($key, 0, now()->addMinutes($window));
            $attempts = (int) $cache->increment($key);
            if ($attempts !== $threshold) {
                return;
            }
            $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
            if (!$user) {
                return;
            }
            event(new \App\Events\SecurityAlertRaised(
                $user,
                'Multiple failed sign-in attempts',
                "There were {$attempts} failed sign-in attempts on your FacultyLens account in the last {$window} minutes. If this was not you, change your password.",
                ['event' => 'FAILED_LOGIN_ATTEMPTS', 'attempts' => $attempts, 'window_minutes' => $window],
                'SECURITY_ALERT:user:' . $user->id . ':failed_logins:' . now()->format('YmdH'),
            ));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed-login tracking unavailable: ' . $e->getMessage());
        }
    }
}

