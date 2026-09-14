<?php

namespace App\Notifications;

/**
 * STEP 47: notification type constants. The authoritative list (with category/severity) lives in config/notifications.php.
 */
final class NotificationType
{
    public const AI_ANALYSIS_COMPLETED = 'AI_ANALYSIS_COMPLETED';
    public const AI_ANALYSIS_FAILED = 'AI_ANALYSIS_FAILED';
    public const AI_RECOMMENDATION_CREATED = 'AI_RECOMMENDATION_CREATED';
    public const RUBRIC_GENERATED = 'RUBRIC_GENERATED';
    public const RUBRIC_GENERATION_FAILED = 'RUBRIC_GENERATION_FAILED';
    public const QUESTION_GENERATION_COMPLETED = 'QUESTION_GENERATION_COMPLETED';
    public const QUESTION_GENERATION_FAILED = 'QUESTION_GENERATION_FAILED';

    public const ASSESSMENT_VERSION_CREATED = 'ASSESSMENT_VERSION_CREATED';
    public const ASSESSMENT_VERSION_APPROVED = 'ASSESSMENT_VERSION_APPROVED';
    public const ASSESSMENT_VERSION_FINALIZED = 'ASSESSMENT_VERSION_FINALIZED';
    public const ASSESSMENT_VERSION_ARCHIVED = 'ASSESSMENT_VERSION_ARCHIVED';
    public const ASSESSMENT_VERSION_RESTORED = 'ASSESSMENT_VERSION_RESTORED';

    public const COLLABORATION_INVITATION = 'COLLABORATION_INVITATION';
    public const COLLABORATION_ACCEPTED = 'COLLABORATION_ACCEPTED';
    public const COLLABORATION_REJECTED = 'COLLABORATION_REJECTED';
    public const COLLABORATION_REMOVED = 'COLLABORATION_REMOVED';
    public const COLLABORATION_ROLE_CHANGED = 'COLLABORATION_ROLE_CHANGED';
    public const COMMENT_CREATED = 'COMMENT_CREATED';
    public const MENTION_RECEIVED = 'MENTION_RECEIVED';

    public const REVIEW_ASSIGNED = 'REVIEW_ASSIGNED';
    public const REVIEW_COMPLETED = 'REVIEW_COMPLETED';

    public const GRADING_COMPLETED = 'GRADING_COMPLETED';
    public const GRADING_FAILED = 'GRADING_FAILED';
    public const INTER_GRADER_REVIEW_REQUIRED = 'INTER_GRADER_REVIEW_REQUIRED';

    public const PERFORMANCE_ANALYSIS_COMPLETED = 'PERFORMANCE_ANALYSIS_COMPLETED';
    public const PERFORMANCE_ANALYSIS_FAILED = 'PERFORMANCE_ANALYSIS_FAILED';
    public const LEARNING_GAP_DETECTED = 'LEARNING_GAP_DETECTED';

    public const REPORT_GENERATED = 'REPORT_GENERATED';
    public const REPORT_GENERATION_FAILED = 'REPORT_GENERATION_FAILED';

    public const FACULTY_FEEDBACK_RECEIVED = 'FACULTY_FEEDBACK_RECEIVED';

    public const SECURITY_ALERT = 'SECURITY_ALERT';
    public const SYSTEM_ALERT = 'SYSTEM_ALERT';

    /** @return string[] */
    public static function all(): array
    {
        return array_keys((array) config('notifications.types', []));
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, (array) config('notifications.types', []));
    }

    public static function category(string $type): ?string
    {
        return config("notifications.types.{$type}.0");
    }

    public static function defaultSeverity(string $type): string
    {
        return config("notifications.types.{$type}.1", NotificationSeverity::INFO);
    }

    public static function label(string $type): string
    {
        return config("notifications.types.{$type}.2", str_replace('_', ' ', ucfirst(strtolower($type))));
    }

    /** @return string[] */
    public static function forCategory(string $category): array
    {
        return array_keys(array_filter((array) config('notifications.types', []), fn ($def) => ($def[0] ?? null) === $category));
    }
}
