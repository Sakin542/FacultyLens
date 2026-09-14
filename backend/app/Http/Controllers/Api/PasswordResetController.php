<?php

namespace App\Http\Controllers\Api;

use App\Events\SecurityAlertRaised;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

/**
 * Password recovery on top of Laravel's password broker.
 *
 *   POST /api/auth/forgot-password  → broker creates a hashed, expiring token → User::sendPasswordResetNotification()
 *                                     → EmailService (queue → Horizon → SMTP). Response is identical for known and
 *                                     unknown addresses (no account enumeration), including when the broker throttles.
 *   POST /api/auth/reset-password   → broker validates token + e-mail, password is hashed, token consumed, every other
 *                                     session/token is invalidated. The user is NOT signed in automatically.
 *
 * Passwords, confirmations and tokens never reach a log line or an audit row.
 */
class PasswordResetController extends Controller
{
    public const GENERIC_MESSAGE = 'If an account exists for this email address, a password reset link has been sent.';

    public function __construct(protected AuditLogService $audit) {}

    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
        ]);
        $email = strtolower(trim($validated['email']));

        try {
            $status = Password::broker()->sendResetLink(['email' => $email]);
            if ($status !== Password::RESET_LINK_SENT) {
                // equalise response time with the token-hashing path so timing does not reveal account existence
                Hash::make(Str::random(40));
            }
            $user = $status === Password::RESET_LINK_SENT ? User::query()->where('email', $email)->first(['id']) : null;
            $this->audit->log('PASSWORD_RESET_REQUESTED', 'User', $user?->id, [
                'outcome' => $this->outcome($status),
                'email_domain' => $this->domain($email),
            ], $user ? User::find($user->id) : null);
        } catch (Throwable $e) {
            // Broker/queue failure must not leak account existence and must not surface internals.
            Log::error('Password reset request failed', ['error' => get_class($e), 'email_domain' => $this->domain($email)]);
        }

        return response()->json(['status' => 'success', 'message' => self::GENERIC_MESSAGE], 200);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers(), 'max:128'],
        ]);
        $email = strtolower(trim($validated['email']));

        $status = Password::broker()->reset(
            ['email' => $email, 'password' => $validated['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $validated['token']],
            function (User $user, string $password) use ($request): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                // every other session / API token stops working; the browser that performed the reset is not signed in
                if (method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }
                event(new PasswordReset($user));

                $this->audit->log('PASSWORD_RESET_COMPLETED', 'User', $user->id, ['email_domain' => $this->domain($user->email)], $user);
                event(new SecurityAlertRaised(
                    $user,
                    'Your password was reset',
                    'The password for your FacultyLens account was reset using a password-reset link. If you did not do this, contact your administrator immediately.',
                    ['event' => 'PASSWORD_RESET'],
                    'SECURITY_ALERT:user:' . $user->id . ':password_reset:' . now()->timestamp,
                ));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['status' => 'success', 'message' => 'Password reset successfully. You can now sign in with your new password.']);
        }

        $user = User::query()->where('email', $email)->first(['id']);
        $this->audit->log('PASSWORD_RESET_FAILED', 'User', $user?->id, ['outcome' => $this->outcome($status), 'email_domain' => $this->domain($email)], $user ? User::find($user->id) : null);

        // Invalid token, expired token, reused token and unknown e-mail all collapse into one message.
        return response()->json([
            'status' => 'error',
            'code' => 'INVALID_RESET_TOKEN',
            'message' => 'This password reset link is invalid or has expired.',
        ], 422);
    }

    protected function outcome(string $status): string
    {
        return match ($status) {
            Password::RESET_LINK_SENT => 'LINK_SENT',
            Password::RESET_THROTTLED => 'THROTTLED',
            Password::INVALID_USER => 'UNKNOWN_ACCOUNT',
            Password::INVALID_TOKEN => 'INVALID_TOKEN',
            Password::PASSWORD_RESET => 'RESET',
            default => 'OTHER',
        };
    }

    protected function domain(string $email): ?string
    {
        return str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : null;
    }
}
