<?php

/**
 * FacultyLens e-mail system.
 *
 *   domain event → NotificationService → [email preference] → EmailService → email_deliveries row (idempotent)
 *                → SendFacultyLensEmailJob (queue "emails", Redis/Horizon) → Mail (Gmail SMTP) → SENT/FAILED.
 *
 * Nothing here holds a credential: SMTP settings live in config/mail.php and are read from the environment only.
 * E-mails are informational — they never approve, finalize, grade or decide anything.
 */
return [

    /** Master switch. When false no e-mail is queued (in-app notifications are unaffected). */
    'enabled' => (bool) env('EMAIL_ENABLED', true),

    /** Public frontend origin used to build every link inside an e-mail (never taken from a request). */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    'brand' => [
        'name' => 'FacultyLens',
        'tagline' => 'AI-Powered Academic Decision Support',
        'subject_prefix' => 'FacultyLens — ',
    ],

    /** Queue settings for SendFacultyLensEmailJob. Connection null => queue.default (redis with Horizon). */
    'queue' => [
        'connection' => env('EMAIL_QUEUE_CONNECTION'),
        'name' => env('EMAIL_QUEUE', 'emails'),
        'tries' => (int) env('EMAIL_QUEUE_TRIES', 4),
        'backoff' => [30, 120, 600],
        'timeout' => (int) env('EMAIL_QUEUE_TIMEOUT', 90),
    ],

    /**
     * Preference model. A notification type is e-mailed when the recipient enabled e-mail for it
     * (notification_preferences.email_enabled). Categories listed as mandatory ignore the stored flag.
     * Authentication e-mails (password reset) are not notifications and are always sent.
     */
    'mandatory_categories' => ['SECURITY'],

    /** Default e-mail flag per category when the user has never saved a preference row. */
    'default_email_enabled' => [
        'AI' => true,
        'ASSESSMENT' => false,
        'COLLABORATION' => true,
        'REVIEW' => true,
        'GRADING' => true,
        'PERFORMANCE' => true,
        'REPORT' => true,
        'FEEDBACK' => true,
        'SECURITY' => true,
        'SYSTEM' => true,
    ],

    /** Notification types that are never e-mailed even when the category is enabled (low value / noisy). */
    'never_email_types' => [
        'ASSESSMENT_VERSION_RESTORED',
        'COLLABORATION_ROLE_CHANGED',
    ],

    /**
     * Storm protection. Identical events (same idempotency key) are sent once; beyond that a recipient receives at
     * most `per_user_per_hour` non-security e-mails. SECURITY / AUTH e-mails use the separate, higher limit.
     */
    'rate_limit' => [
        'per_user_per_hour' => (int) env('EMAIL_RATE_LIMIT_PER_USER_PER_HOUR', 30),
        'security_per_user_per_hour' => (int) env('EMAIL_SECURITY_RATE_LIMIT_PER_USER_PER_HOUR', 20),
        /** Same type + same recipient inside this window collapses even when the entity differs (e.g. bursts). */
        'burst_window_seconds' => (int) env('EMAIL_BURST_WINDOW_SECONDS', 20),
        'burst_max_per_type' => (int) env('EMAIL_BURST_MAX_PER_TYPE', 5),
    ],

    /** Delivery rows older than this are purged by email:purge-deliveries (0 disables). */
    'retention_days' => (int) env('EMAIL_DELIVERY_RETENTION_DAYS', 180),

    /**
     * Developer / operator surfaces. The test endpoint sends a real message through the queue and SMTP, so it is off
     * in production unless explicitly enabled. Preview never sends anything.
     */
    'test_endpoint_enabled' => (bool) env('EMAIL_TEST_ENDPOINT_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'preview_enabled' => (bool) env('EMAIL_PREVIEW_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'test_rate_limit_per_hour' => (int) env('EMAIL_TEST_RATE_LIMIT_PER_HOUR', 5),

    /** Automated suites never touch Gmail; set true locally to run the single live SMTP test. */
    'live_testing' => (bool) env('EMAIL_LIVE_TESTING', false),

    /**
     * Notification type => blade view under resources/views/emails. Types not listed use the generic
     * `notification` template. Subject lines are built in EmailContentResolver from the same key.
     */
    'templates' => [
        'AI_ANALYSIS_COMPLETED' => 'assessment-analysis-completed',
        'AI_ANALYSIS_FAILED' => 'assessment-analysis-failed',
        'AI_RECOMMENDATION_CREATED' => 'recommendations-created',
        'RUBRIC_GENERATED' => 'rubric-generated',
        'RUBRIC_GENERATION_FAILED' => 'notification',
        'QUESTION_GENERATION_COMPLETED' => 'question-generation-completed',
        'QUESTION_GENERATION_FAILED' => 'notification',
        'ASSESSMENT_VERSION_CREATED' => 'assessment-version',
        'ASSESSMENT_VERSION_APPROVED' => 'assessment-version',
        'ASSESSMENT_VERSION_FINALIZED' => 'assessment-version',
        'ASSESSMENT_VERSION_ARCHIVED' => 'assessment-version',
        'COLLABORATION_INVITATION' => 'collaboration-invitation',
        'COLLABORATION_ACCEPTED' => 'notification',
        'COLLABORATION_REJECTED' => 'notification',
        'COLLABORATION_REMOVED' => 'notification',
        'COMMENT_CREATED' => 'notification',
        'MENTION_RECEIVED' => 'notification',
        'REVIEW_ASSIGNED' => 'review-assigned',
        'REVIEW_COMPLETED' => 'notification',
        'GRADING_COMPLETED' => 'notification',
        'GRADING_FAILED' => 'notification',
        'INTER_GRADER_REVIEW_REQUIRED' => 'grading-review-required',
        'PERFORMANCE_ANALYSIS_COMPLETED' => 'performance-analysis-ready',
        'PERFORMANCE_ANALYSIS_FAILED' => 'notification',
        'LEARNING_GAP_DETECTED' => 'learning-gap-detected',
        'REPORT_GENERATED' => 'report-ready',
        'REPORT_GENERATION_FAILED' => 'notification',
        'FACULTY_FEEDBACK_RECEIVED' => 'notification',
        'SECURITY_ALERT' => 'security-alert',
        'SYSTEM_ALERT' => 'notification',
        // authentication (not a notification type)
        'PASSWORD_RESET' => 'reset-password',
        'TEST_EMAIL' => 'test-email',
    ],

    /** Subject lines (without prefix). Types not listed fall back to the notification title. */
    'subjects' => [
        'AI_ANALYSIS_COMPLETED' => 'Assessment Analysis Completed',
        'AI_ANALYSIS_FAILED' => 'Assessment Analysis Failed',
        'AI_RECOMMENDATION_CREATED' => 'New Assessment Recommendations',
        'RUBRIC_GENERATED' => 'Rubric Draft Ready',
        'RUBRIC_GENERATION_FAILED' => 'Rubric Generation Failed',
        'QUESTION_GENERATION_COMPLETED' => 'Question Drafts Ready',
        'QUESTION_GENERATION_FAILED' => 'Question Generation Failed',
        'ASSESSMENT_VERSION_CREATED' => 'Assessment Version Created',
        'ASSESSMENT_VERSION_APPROVED' => 'Assessment Version Approved',
        'ASSESSMENT_VERSION_FINALIZED' => 'Assessment Version Finalized',
        'ASSESSMENT_VERSION_ARCHIVED' => 'Assessment Version Archived',
        'COLLABORATION_INVITATION' => 'Collaboration Invitation',
        'COLLABORATION_ACCEPTED' => 'Collaboration Invitation Accepted',
        'COLLABORATION_REJECTED' => 'Collaboration Invitation Declined',
        'COLLABORATION_REMOVED' => 'Collaboration Access Removed',
        'COMMENT_CREATED' => 'New Collaboration Comment',
        'MENTION_RECEIVED' => 'You Were Mentioned',
        'REVIEW_ASSIGNED' => 'Review Assigned',
        'REVIEW_COMPLETED' => 'Review Completed',
        'GRADING_COMPLETED' => 'Grading Suggestion Ready',
        'GRADING_FAILED' => 'Grading Could Not Be Completed',
        'INTER_GRADER_REVIEW_REQUIRED' => 'Grading Consistency Review Recommended',
        'PERFORMANCE_ANALYSIS_COMPLETED' => 'Performance Analysis Ready',
        'PERFORMANCE_ANALYSIS_FAILED' => 'Performance Analysis Failed',
        'LEARNING_GAP_DETECTED' => 'Learning Outcome Review Recommended',
        'REPORT_GENERATED' => 'Your Report Is Ready',
        'REPORT_GENERATION_FAILED' => 'Report Generation Failed',
        'FACULTY_FEEDBACK_RECEIVED' => 'New Feedback Received',
        'SECURITY_ALERT' => 'Security Alert',
        'SYSTEM_ALERT' => 'System Notice',
        'PASSWORD_RESET' => 'Reset Your Password',
        'TEST_EMAIL' => 'Test Email',
    ],

    /** Keys that must never reach a template context, a log line or the deliveries table. */
    'forbidden_context_keys' => [
        'password', 'password_confirmation', 'mail_password', 'smtp', 'api_key', 'secret', 'cookie', 'authorization', 'token',
        'answer_text', 'original_answer_text', 'extracted_text', 'raw_text', 'prompt', 'system_prompt', 'student_name',
        'student_identifier', 'student_email', 'awarded_marks',
    ],
];
