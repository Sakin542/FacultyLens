<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * RFC 6238 TOTP (30-second steps, 6 digits, HMAC-SHA1 — what Google Authenticator, Authy and 1Password expect)
 * plus single-use recovery codes. Secrets and recovery codes are stored with Laravel's encrypted casts.
 */
class TwoFactorService
{
    protected const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    protected const PERIOD = 30;
    protected const DIGITS = 6;

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        $issuer = (string) config('admin.two_factor.issuer', 'FacultyLens');
        $label = rawurlencode($issuer.':'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer=".rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    /**
     * Verify a code for the given secret. A successfully used time step is remembered per user so the same code
     * cannot be replayed within its validity window.
     */
    public function verify(string $secret, string $code, ?User $user = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{'.self::DIGITS.'}$/', (string) $code)) {
            return false;
        }
        $window = (int) config('admin.two_factor.window', 1);
        $step = intdiv(time(), self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->codeAt($secret, $step + $offset), $code)) {
                if ($user !== null) {
                    $key = '2fa:last-step:'.$user->id;
                    $last = (int) Cache::get($key, PHP_INT_MIN);
                    if ($step + $offset <= $last) {
                        return false;
                    }
                    Cache::put($key, $step + $offset, now()->addMinutes(5));
                }

                return true;
            }
        }

        return false;
    }

    /** @return list<string> plaintext codes in XXXXX-XXXXX form (shown once, stored encrypted) */
    public function generateRecoveryCodes(): array
    {
        $count = (int) config('admin.two_factor.recovery_codes', 8);

        return array_map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)), range(1, $count));
    }

    /** Consume a recovery code (case-insensitive); returns false if it is not one of the user's unused codes. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(trim($code));
        $codes = (array) ($user->two_factor_recovery_codes ?? []);
        foreach ($codes as $i => $stored) {
            if (hash_equals(strtoupper((string) $stored), $normalized)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function codeAt(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $binaryStep = pack('N*', 0).pack('N*', $step);
        $hash = hash_hmac('sha1', $binaryStep, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function currentCode(string $secret): string
    {
        return $this->codeAt($secret, intdiv(time(), self::PERIOD));
    }

    protected function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    protected function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
