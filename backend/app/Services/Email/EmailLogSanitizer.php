<?php

namespace App\Services\Email;

/**
 * Strips anything that could be an SMTP credential or token from text destined for logs, audit rows or the
 * email_deliveries table. Symfony transport exceptions may echo the AUTH dialogue, so this is applied to every
 * error message before it is stored.
 */
final class EmailLogSanitizer
{
    public const MASK = '********';

    /** Sensitive keys (case-insensitive substring match) removed from arrays. */
    private const FORBIDDEN_KEYS = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'mail_password', 'smtp_password',
        'dsn', 'reset_url', 'reset_link',
    ];

    public static function string(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        foreach (self::secretValues() as $secret) {
            $text = str_replace($secret, self::MASK, $text);
            $encoded = base64_encode($secret);
            if ($encoded !== '') {
                $text = str_replace($encoded, self::MASK, $text);
            }
        }

        // SMTP AUTH dialogue lines / DSNs that carry credentials inline
        $text = preg_replace('/(AUTH\s+(?:PLAIN|LOGIN|XOAUTH2)\s+)\S+/i', '$1' . self::MASK, $text) ?? $text;
        $text = preg_replace('/(smtps?:\/\/)([^:\/\s]+):([^@\/\s]+)@/i', '$1$2:' . self::MASK . '@', $text) ?? $text;
        $text = preg_replace('/((?:password|passwd|secret|token|api[_-]?key)\s*[=:]\s*)[^\s,;&]+/i', '$1' . self::MASK, $text) ?? $text;
        // password reset tokens / signed URLs must never be logged
        $text = preg_replace('/([?&]token=)[^&\s]+/i', '$1' . self::MASK, $text) ?? $text;

        return mb_substr($text, 0, 500);
    }

    /** @param array<string, mixed> $context */
    public static function context(array $context, int $depth = 0): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (str_contains($lower, $forbidden)) {
                    $clean[$key] = self::MASK;
                    continue 2;
                }
            }
            if (is_array($value)) {
                $clean[$key] = $depth < 3 ? self::context($value, $depth + 1) : '[array]';
            } elseif (is_string($value)) {
                $clean[$key] = self::string($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[' . get_debug_type($value) . ']';
            }
        }

        return $clean;
    }

    /** Domain part only — the local part of an address is personal data and not needed for diagnostics. */
    public static function domain(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }

        return strtolower(substr(strrchr($email, '@'), 1));
    }

    /** @return list<string> */
    private static function secretValues(): array
    {
        $values = [];
        foreach ((array) config('mail.mailers', []) as $mailer) {
            foreach (['password', 'url', 'dsn'] as $key) {
                $value = $mailer[$key] ?? null;
                if (is_string($value) && strlen($value) >= 6) {
                    $values[] = $value;
                }
            }
        }
        foreach ([config('services.ai.api_key'), config('database.redis.default.password')] as $value) {
            if (is_string($value) && strlen($value) >= 6) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
