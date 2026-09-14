<?php

/*
|--------------------------------------------------------------------------
| Profile picture (avatar) policy
|--------------------------------------------------------------------------
| Faculty avatars are validated (MIME sniffing + real image decoding), normalised to a
| square WEBP by the image processor and stored on a PRIVATE disk. Images are only ever
| served through authenticated, authorised API endpoints.
*/

return [
    // Private disk + directory. The public disk is deliberately not allowed.
    'disk' => env('PROFILE_PICTURE_DISK', 'local'),
    'directory' => 'profile-pictures',

    // Upload limits
    'max_size_mb' => (int) env('PROFILE_PICTURE_MAX_SIZE_MB', 5),
    'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],

    // Dimension policy (pixels). max_pixels guards against decompression bombs
    // independently of the declared width/height.
    'min_dimension' => 100,
    'max_dimension' => 5000,
    'max_pixels' => 25_000_000,

    // Normalised output written to storage
    'output_size' => 512,
    'output_format' => 'webp',
    'output_quality' => 85,

    // When true, uploads fail (503) if the server cannot normalise the image (no GD).
    // Defaults to strict in production; development installs without GD store the validated original.
    'require_processing' => filter_var(env('PROFILE_PICTURE_REQUIRE_PROCESSING', env('APP_ENV') === 'production'), FILTER_VALIDATE_BOOL),

    // Abuse protection for POST/DELETE (per authenticated user)
    'rate_limit_per_hour' => (int) env('PROFILE_PICTURE_RATE_LIMIT_PER_HOUR', 10),

    // Browser caching of the private image response (URL carries a version token, so this is safe)
    'cache_max_age_seconds' => (int) env('PROFILE_PICTURE_CACHE_MAX_AGE', 86400),
];
