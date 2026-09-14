<?php

namespace App\Services\Profile;

use RuntimeException;

/**
 * Raised for any profile-picture failure the caller should surface to the user.
 * $status is the HTTP status the API should respond with; $message is already user-safe.
 */
class ProfilePictureException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }

    public static function invalid(string $message, string $reason = 'invalid'): self
    {
        return new self($message, 422, $reason);
    }

    public static function processingUnavailable(): self
    {
        return new self('Profile pictures cannot be processed right now. Please try again later.', 503, 'processing_unavailable');
    }

    public static function processingFailed(): self
    {
        return new self('We could not process that image. Please try a different JPG, PNG, or WEBP file.', 422, 'processing_failed');
    }

    public static function storageFailed(): self
    {
        return new self('Unable to update your profile picture. Please try again.', 503, 'storage_failed');
    }
}
