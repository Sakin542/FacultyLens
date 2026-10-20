<?php

return [
    /*
     * The single account allowed to hold the ADMIN role and open the admin panel. When set, isAdmin() requires both
     * the ADMIN role and this e-mail, and `php artisan admin:ensure` demotes any other ADMIN account to FACULTY.
     * Leave empty only for test suites, where role alone decides.
     */
    'email' => strtolower(trim((string) env('ADMIN_EMAIL', ''))),

    // Used only by `php artisan admin:ensure` to create/reset the account; never read at request time.
    'password' => env('ADMIN_PASSWORD'),

    'name' => env('ADMIN_NAME', 'FacultyLens Administrator'),

    // Shown in the admin panel's environment view; falls back to the git commit when unset
    'app_version' => env('APP_VERSION'),

    'backups' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'directory' => env('BACKUP_DIRECTORY', 'backups'),
        // Oldest completed backups beyond this count are deleted after each successful run
        'keep' => (int) env('BACKUP_KEEP', 14),
        'schedule_time' => env('BACKUP_SCHEDULE_TIME', '02:00'),
        'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 600),
    ],

    // Service monitoring (admin:monitor runs every five minutes from the scheduler)
    'monitor' => [
        'history_days' => (int) env('ADMIN_HEALTH_HISTORY_DAYS', 14),
        'probe_timeout_seconds' => (int) env('ADMIN_PROBE_TIMEOUT_SECONDS', 3),
        // A scheduler heartbeat older than this means admin:monitor (and therefore the scheduler) is not running
        'heartbeat_stale_minutes' => (int) env('ADMIN_HEARTBEAT_STALE_MINUTES', 15),
        'storage_low_free_percent' => (int) env('ADMIN_STORAGE_LOW_FREE_PERCENT', 10),
    ],

    // Alert thresholds; a rule set to 0 is disabled
    'alerts' => [
        'queue_backlog' => (int) env('ADMIN_ALERT_QUEUE_BACKLOG', 200),
        'failed_jobs_per_hour' => (int) env('ADMIN_ALERT_FAILED_JOBS_PER_HOUR', 5),
        'email_failures_per_day' => (int) env('ADMIN_ALERT_EMAIL_FAILURES_PER_DAY', 5),
        'backup_max_age_hours' => (int) env('ADMIN_ALERT_BACKUP_MAX_AGE_HOURS', 26),
        'failed_logins_per_hour' => (int) env('ADMIN_ALERT_FAILED_LOGINS_PER_HOUR', 20),
    ],

    'exports' => [
        'disk' => env('ADMIN_EXPORT_DISK', 'local'),
        'directory' => 'admin-exports',
        'ttl_hours' => (int) env('ADMIN_EXPORT_TTL_HOURS', 24),
        'max_rows' => (int) env('ADMIN_EXPORT_MAX_ROWS', 50000),
    ],

    'imports' => [
        'max_rows' => (int) env('ADMIN_IMPORT_MAX_ROWS', 500),
        'max_kilobytes' => 512,
    ],

    'two_factor' => [
        'issuer' => env('TWO_FACTOR_ISSUER', 'FacultyLens'),
        // Accept codes from one 30-second step either side to tolerate clock drift
        'window' => 1,
        'challenge_ttl_minutes' => 5,
        'recovery_codes' => 8,
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        // Comma-separated e-mail domains allowed to sign in with Google (e.g. "aust.edu"); empty allows any verified address
        'allowed_domains' => array_values(array_filter(array_map(
            fn ($d) => strtolower(trim($d)),
            explode(',', (string) env('GOOGLE_ALLOWED_DOMAINS', ''))
        ))),
        'tokeninfo_url' => 'https://oauth2.googleapis.com/tokeninfo',
    ],
];
