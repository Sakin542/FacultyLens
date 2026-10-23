<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'broadcasting/auth',
    ],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Explicitly allow the deployed FacultyLens frontend.
    | Additional origins can be configured through CORS_ALLOWED_ORIGINS.
    |
    */

    'allowed_origins' => array_values(array_unique(array_filter(
        array_merge(
            [
                'https://faculty-lens.vercel.app',
            ],
            array_map(
                'trim',
                explode(
                    ',',
                    (string) env(
                        'CORS_ALLOWED_ORIGINS',
                        env('FRONTEND_URL', '')
                    )
                )
            ),
            env('APP_ENV', 'production') !== 'production'
                ? [
                    'http://localhost:5173',
                    'http://127.0.0.1:5173',
                    'http://localhost:3000',
                    'http://127.0.0.1:3000',
                    'http://localhost:4173',
                    'http://127.0.0.1:4173',
                ]
                : []
        )
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origin Patterns
    |--------------------------------------------------------------------------
    |
    | Allow local development ports only outside production.
    |
    */

    'allowed_origins_patterns' => array_values(array_filter([
        env('APP_ENV', 'production') !== 'production'
            ? '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#'
            : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',
    ],

    'max_age' => (int) env('CORS_MAX_AGE', 7200),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Required for requests using Laravel Sanctum cookie authentication.
    |
    */

    'supports_credentials' => true,

];
