<?php

namespace App\Notifications;

/**
 * STEP 47: notification severities. Severity describes delivery urgency only — it is never an academic judgement.
 */
final class NotificationSeverity
{
    public const INFO = 'INFO';
    public const SUCCESS = 'SUCCESS';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /** @return string[] */
    public static function all(): array
    {
        return (array) config('notifications.severities', []);
    }

    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::all(), true);
    }
}
