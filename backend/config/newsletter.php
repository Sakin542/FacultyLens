<?php

return [
    // Public sign-ups per IP per hour (the footer form)
    'subscribe_rate_limit_per_hour' => (int) env('NEWSLETTER_RATE_LIMIT_PER_HOUR', 5),

    // Minimum gap before a confirmation e-mail is re-sent to the same unconfirmed address
    'resend_cooldown_minutes' => (int) env('NEWSLETTER_RESEND_COOLDOWN_MINUTES', 10),

    // Confirmation links expire after this many hours; unconfirmed rows older than purge_unconfirmed_after_days are deleted
    'confirmation_ttl_hours' => (int) env('NEWSLETTER_CONFIRMATION_TTL_HOURS', 48),
    'purge_unconfirmed_after_days' => (int) env('NEWSLETTER_PURGE_UNCONFIRMED_AFTER_DAYS', 7),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'sender_name' => env('NEWSLETTER_SENDER_NAME', 'FacultyLens'),
];
