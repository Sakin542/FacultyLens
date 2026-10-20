<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service two-factor setup for the signed-in user: start (secret + otpauth URL), confirm with a first code,
 * regenerate recovery codes, disable. Sensitive steps require the current password.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactorService $twoFactor, protected AuditLogService $audit) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => !$user->hasTwoFactorEnabled() && !empty($user->two_factor_secret),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => $user->hasTwoFactorEnabled() ? count((array) $user->two_factor_recovery_codes) : 0,
            ],
        ]);
    }

    /** Begin setup: a fresh secret replaces any unconfirmed one. The secret is shown once for manual entry. */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (!$this->passwordMatches($request)) {
            return $this->wrongPassword();
        }
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['status' => 'error', 'message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'secret' => $secret,
                'otpauth_url' => $this->twoFactor->otpauthUrl($user, $secret),
            ],
        ]);
    }

    /** Finish setup with the first code from the app; returns the recovery codes (shown once). */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $user = $request->user();
        if ($user->hasTwoFactorEnabled() || empty($user->two_factor_secret)) {
            return response()->json(['status' => 'error', 'message' => 'Start two-factor setup first.'], 409);
        }
        if (!$this->twoFactor->verify((string) $user->two_factor_secret, $validated['code'], $user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The verification code is incorrect. Check the time on your phone and try again.',
                'errors' => ['code' => ['The verification code is incorrect.']],
            ], 422);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $this->audit->log('TWO_FACTOR_ENABLED', $user, $user->id, null, $user);
        event(new \App\Events\SecurityAlertRaised(
            $user,
            'Two-factor authentication enabled',
            'Two-factor authentication was turned on for your FacultyLens account. If this was not you, contact your administrator.',
            ['event' => 'TWO_FACTOR_ENABLED'],
            'SECURITY_ALERT:user:'.$user->id.':2fa_enabled:'.now()->timestamp,
        ));

        return response()->json(['status' => 'success', 'data' => ['recovery_codes' => $codes]]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (!$this->passwordMatches($request)) {
            return $this->wrongPassword();
        }
        if (!$user->hasTwoFactorEnabled()) {
            return response()->json(['status' => 'error', 'message' => 'Two-factor authentication is not enabled.'], 409);
        }
        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->audit->log('TWO_FACTOR_RECOVERY_CODES_REGENERATED', $user, $user->id, null, $user);

        return response()->json(['status' => 'success', 'data' => ['recovery_codes' => $codes]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (!$this->passwordMatches($request)) {
            return $this->wrongPassword();
        }
        $wasEnabled = $user->hasTwoFactorEnabled();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($wasEnabled) {
            $this->audit->log('TWO_FACTOR_DISABLED', $user, $user->id, null, $user);
            event(new \App\Events\SecurityAlertRaised(
                $user,
                'Two-factor authentication disabled',
                'Two-factor authentication was turned off for your FacultyLens account. If this was not you, change your password now.',
                ['event' => 'TWO_FACTOR_DISABLED'],
                'SECURITY_ALERT:user:'.$user->id.':2fa_disabled:'.now()->timestamp,
            ));
        }

        return response()->json(['status' => 'success', 'message' => 'Two-factor authentication is off.']);
    }

    protected function passwordMatches(Request $request): bool
    {
        return Hash::check((string) $request->input('password'), (string) $request->user()->password);
    }

    protected function wrongPassword(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'The password is incorrect.',
            'errors' => ['password' => ['The password is incorrect.']],
        ], 422);
    }
}
