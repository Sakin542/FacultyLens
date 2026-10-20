<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Production: only the configured frontend origin(s) (comma-separated CORS_ALLOWED_ORIGINS or FRONTEND_URL).
    // Development additionally allows the usual Vite ports; a wildcard is never used with credentials.
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', '')))),
        env('APP_ENV', 'production') !== 'production' ? ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000', 'http://127.0.0.1:3000'] : []
    )))),

    // Any localhost / 127.0.0.1 port (Vite falls back to 3001, 5174, … when the default port is busy)
    'allowed_origins_patterns' => array_values(array_filter([
        env('APP_ENV', 'production') !== 'production' ? '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#' : null,
    ])),

    'allowed_headers' => ['*'],

    // Lets the SPA read download filenames (exam papers, reports) on cross-origin file responses
    'exposed_headers' => ['Content-Disposition'],

    // Let browsers cache the preflight answer (Chrome caps it at 2 h) instead of sending an extra OPTIONS
    // request before almost every API call. Only the permission check is cached, not any data.
    'max_age' => (int) env('CORS_MAX_AGE', 7200),

    'supports_credentials' => true,

];
