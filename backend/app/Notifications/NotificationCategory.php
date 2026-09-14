<?php

namespace App\Notifications;

/** STEP 47: notification categories (mirrors config('notifications.categories')). */
final class NotificationCategory
{
    public const AI = 'AI';
    public const ASSESSMENT = 'ASSESSMENT';
    public const COLLABORATION = 'COLLABORATION';
    public const REVIEW = 'REVIEW';
    public const GRADING = 'GRADING';
    public const PERFORMANCE = 'PERFORMANCE';
    public const REPORT = 'REPORT';
    public const FEEDBACK = 'FEEDBACK';
    public const SECURITY = 'SECURITY';
    public const SYSTEM = 'SYSTEM';

    /** @return string[] */
    public static function all(): array
    {
        return (array) config('notifications.categories', []);
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /** Categories a faculty member may not switch off. */
    public static function isMandatory(string $category): bool
    {
        return in_array($category, (array) config('notifications.mandatory_categories', []), true);
    }
}
