<?php

namespace App\Services\Profile;

/**
 * Result of validating + normalising an uploaded avatar. `contents` is the binary to store.
 */
final class ProcessedImage
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $extension,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $normalized,
        public readonly string $sourceMimeType,
        public readonly int $sourceWidth,
        public readonly int $sourceHeight,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
