<?php

/**
 * STEP 47: FacultyLens notification system.
 *
 * Central registry of notification categories, severities and types. Everything that creates a notification
 * goes through App\Services\Notification\NotificationService, which validates against this file. Frontend enums
 * in frontend/src/types/notification.ts mirror these values — keep them in sync.
 *
 * A notification is informational only. Nothing here approves, finalizes, grades or decides anything.
 */
return [

    'categories' => ['AI', 'ASSESSMENT', 'COLLABORATION', 'REVIEW', 'GRADING', 'PERFORMANCE', 'REPORT', 'FEEDBACK', 'SECURITY', 'SYSTEM'],

    'severities' => ['INFO', 'SUCCESS', 'WARNING', 'ERROR', 'CRITICAL'],

    /** Categories faculty cannot mute — account safety and platform incidents are always delivered. */
    'mandatory_categories' => ['SECURITY', 'SYSTEM'],

    /** type => [category, default severity, human label] */
    'types' => [
        'AI_ANALYSIS_COMPLETED' => ['AI', 'SUCCESS', 'Assessment analysis completed'],
        'AI_ANALYSIS_FAILED' => ['AI', 'ERROR', 'AI analysis failed'],
        'AI_RECOMMENDATION_CREATED' => ['AI', 'INFO', 'New assessment recommendations'],
        'RUBRIC_GENERATED' => ['AI', 'SUCCESS', 'Rubric draft generated'],
        'RUBRIC_GENERATION_FAILED' => ['AI', 'ERROR', 'Rubric generation failed'],
        'QUESTION_GENERATION_COMPLETED' => ['AI', 'SUCCESS', 'Question generation completed'],
        'QUESTION_GENERATION_FAILED' => ['AI', 'ERROR', 'Question generation failed'],

        'ASSESSMENT_VERSION_CREATED' => ['ASSESSMENT', 'INFO', 'Assessment version created'],
        'ASSESSMENT_VERSION_APPROVED' => ['ASSESSMENT', 'SUCCESS', 'Assessment version approved'],
        'ASSESSMENT_VERSION_FINALIZED' => ['ASSESSMENT', 'SUCCESS', 'Assessment version finalized'],
        'ASSESSMENT_VERSION_ARCHIVED' => ['ASSESSMENT', 'INFO', 'Assessment version archived'],
        'ASSESSMENT_VERSION_RESTORED' => ['ASSESSMENT', 'INFO', 'Assessment version restored'],

        'COLLABORATION_INVITATION' => ['COLLABORATION', 'INFO', 'Collaboration invitation'],
        'COLLABORATION_ACCEPTED' => ['COLLABORATION', 'SUCCESS', 'Collaboration invitation accepted'],
        'COLLABORATION_REJECTED' => ['COLLABORATION', 'INFO', 'Collaboration invitation declined'],
        'COLLABORATION_REMOVED' => ['COLLABORATION', 'WARNING', 'Collaboration access removed'],
        'COLLABORATION_ROLE_CHANGED' => ['COLLABORATION', 'INFO', 'Collaboration role changed'],
        'COMMENT_CREATED' => ['COLLABORATION', 'INFO', 'New collaboration comment'],
        'MENTION_RECEIVED' => ['COLLABORATION', 'INFO', 'You were mentioned'],

        'REVIEW_ASSIGNED' => ['REVIEW', 'INFO', 'Review assigned'],
        'REVIEW_COMPLETED' => ['REVIEW', 'SUCCESS', 'Review completed'],

        'GRADING_COMPLETED' => ['GRADING', 'SUCCESS', 'AI grading suggestion ready'],
        'GRADING_FAILED' => ['GRADING', 'ERROR', 'AI grading could not be completed'],
        'INTER_GRADER_REVIEW_REQUIRED' => ['GRADING', 'WARNING', 'Grading consistency review recommended'],

        'PERFORMANCE_ANALYSIS_COMPLETED' => ['PERFORMANCE', 'SUCCESS', 'Performance analysis completed'],
        'PERFORMANCE_ANALYSIS_FAILED' => ['PERFORMANCE', 'ERROR', 'Performance analysis failed'],
        'LEARNING_GAP_DETECTED' => ['PERFORMANCE', 'WARNING', 'Learning outcome review recommended'],

        'REPORT_GENERATED' => ['REPORT', 'SUCCESS', 'Report ready'],
        'REPORT_GENERATION_FAILED' => ['REPORT', 'ERROR', 'Report generation failed'],

        'FACULTY_FEEDBACK_RECEIVED' => ['FEEDBACK', 'INFO', 'Faculty feedback received'],

        'SECURITY_ALERT' => ['SECURITY', 'CRITICAL', 'Security alert'],
        'SYSTEM_ALERT' => ['SYSTEM', 'WARNING', 'System alert'],
    ],

    /** Failed sign-in attempts on an existing account within the window before a SECURITY_ALERT is raised. */
    'security' => [
        'failed_login_threshold' => (int) env('NOTIFICATIONS_FAILED_LOGIN_THRESHOLD', 5),
        'failed_login_window_minutes' => (int) env('NOTIFICATIONS_FAILED_LOGIN_WINDOW', 15),
    ],

    /** Queue the notification insert (default). Set false to write inline (tests / sync environments do this automatically). */
    'queue' => [
        'enabled' => (bool) env('NOTIFICATIONS_QUEUE_ENABLED', true),
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION'),   // null => queue.default (database dev, redis prod)
        'name' => env('NOTIFICATIONS_QUEUE', 'default'),
        'tries' => (int) env('NOTIFICATIONS_QUEUE_TRIES', 3),
        'backoff' => [10, 30, 60],
    ],

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 50,
        'dropdown_limit' => 10,
    ],

    /** Frontend polling interval hint (seconds). Broadcasting is not configured; the DB is the source of truth. */
    'poll_interval_seconds' => (int) env('NOTIFICATIONS_POLL_INTERVAL', 45),

    /**
     * Retention (days). Read/dismissed notifications older than `read_retention_days` and any notification older than
     * `max_retention_days` are purged by `notifications:purge`. 0 disables the rule. Audit rows are never touched.
     */
    'retention' => [
        'read_retention_days' => (int) env('NOTIFICATIONS_READ_RETENTION_DAYS', 90),
        'max_retention_days' => (int) env('NOTIFICATIONS_MAX_RETENTION_DAYS', 365),
        'default_expiry_days' => [
            'COLLABORATION_INVITATION' => 7,
        ],
    ],

    /** Payload keys that must never be persisted inside notification data. */
    'forbidden_data_keys' => [
        'password', 'password_confirmation', 'token', 'token_hash', 'api_key', 'hf_token', 'secret', 'cookie', 'authorization',
        'answer_text', 'original_answer_text', 'extracted_text', 'raw_text', 'system_prompt', 'prompt', 'email',
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
