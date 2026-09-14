<?php

namespace App\Services\Profile;

use Illuminate\Support\Facades\Log;

/**
 * Validates the real content of an uploaded avatar and normalises it into a square WEBP.
 *
 * Defence in depth: the FormRequest already checks MIME/size/dimensions; this class re-reads the
 * bytes (finfo + getimagesize), rejects mismatched or oversized images, then re-encodes the pixels
 * with GD so nothing from the original container (metadata, appended payloads, EXIF) survives.
 */
class ProfileImageProcessor
{
    /** @var array<string, string> */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var array<string, int> */
    protected const IMAGETYPE_TO_MIME = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    protected array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('profile_picture', []);
    }

    /** True when the server can actually re-encode images. */
    public function canNormalize(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagecopyresampled');
    }

    /**
     * @return array{mime: string, width: int, height: int}
     * @throws ProfilePictureException
     */
    public function inspect(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ProfilePictureException::invalid('The uploaded file could not be read.', 'unreadable');
        }

        $allowed = $this->config['allowed_mime_types'] ?? array_keys(self::EXTENSIONS);
        $sniffed = $this->sniffMime($path);
        if ($sniffed === null || !in_array($sniffed, $allowed, true)) {
            throw ProfilePictureException::invalid('Please upload a JPG, PNG, or WEBP image.', 'unsupported_type');
        }

        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            throw ProfilePictureException::invalid('The file is not a valid image.', 'not_an_image');
        }
        $decodedMime = self::IMAGETYPE_TO_MIME[$info[2] ?? 0] ?? null;
        if ($decodedMime !== $sniffed) {
            throw ProfilePictureException::invalid('The image content does not match its type.', 'mime_mismatch');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $min = (int) ($this->config['min_dimension'] ?? 100);
        $max = (int) ($this->config['max_dimension'] ?? 5000);
        if ($width < $min || $height < $min) {
            throw ProfilePictureException::invalid("Profile picture must be at least {$min} × {$min} pixels.", 'too_small');
        }
        if ($width > $max || $height > $max) {
            throw ProfilePictureException::invalid("Profile picture must be no larger than {$max} × {$max} pixels.", 'too_large');
        }
        if ($width * $height > (int) ($this->config['max_pixels'] ?? 25_000_000)) {
            throw ProfilePictureException::invalid('That image has too many pixels to be processed safely.', 'too_many_pixels');
        }

        return ['mime' => $sniffed, 'width' => $width, 'height' => $height];
    }

    /**
     * @throws ProfilePictureException
     */
    public function process(string $path): ProcessedImage
    {
        $info = $this->inspect($path);

        if (!$this->canNormalize()) {
            if (!empty($this->config['require_processing'])) {
                Log::error('Profile picture upload rejected: GD image extension is not available.');
                throw ProfilePictureException::processingUnavailable();
            }
            Log::warning('GD is not available; storing validated profile picture without normalisation.');

            return $this->passthrough($path, $info);
        }

        $this->assertMemoryBudget($info['width'], $info['height']);

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ProfilePictureException::invalid('The uploaded file could not be read.', 'unreadable');
        }

        $source = @imagecreatefromstring($raw);
        if ($source === false) {
            throw ProfilePictureException::processingFailed();
        }

        try {
            if ($info['mime'] === 'image/jpeg') {
                $source = $this->applyExifOrientation($source, $path);
            }
            $target = $this->squareResize($source, (int) ($this->config['output_size'] ?? 512));
            [$contents, $mime, $ext] = $this->encode($target);
            imagedestroy($target);
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
        }

        if ($contents === '' || $contents === false) {
            throw ProfilePictureException::processingFailed();
        }
        $outSize = (int) ($this->config['output_size'] ?? 512);

        return new ProcessedImage($contents, $mime, $ext, $outSize, $outSize, true, $info['mime'], $info['width'], $info['height']);
    }

    // ------------------------------------------------------------------ internals

    protected function passthrough(string $path, array $info): ProcessedImage
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw ProfilePictureException::invalid('The uploaded file could not be read.', 'unreadable');
        }

        return new ProcessedImage($raw, $info['mime'], self::EXTENSIONS[$info['mime']], $info['width'], $info['height'], false, $info['mime'], $info['width'], $info['height']);
    }

    protected function sniffMime(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            // Fall back to the image header parser; getimagesize() is stricter than the extension anyway.
            $info = @getimagesize($path);

            return $info === false ? null : (self::IMAGETYPE_TO_MIME[$info[2] ?? 0] ?? null);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : null;
    }

    /** Rejects images whose decoded bitmap would not fit comfortably in the PHP memory limit. */
    protected function assertMemoryBudget(int $width, int $height): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit <= 0) {
            return;
        }
        $needed = $width * $height * 5 + 4 * 1024 * 1024; // GD truecolor ≈ 4–5 bytes/pixel + output canvas
        $available = $limit - memory_get_usage(true);
        if ($needed > $available * 0.8) {
            throw ProfilePictureException::invalid('That image is too large to be processed. Please upload a smaller image.', 'memory_budget');
        }
    }

    protected function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    protected function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation <= 1 || $orientation > 8) {
            return $image;
        }

        $rotated = match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), -90, 0),
            6 => imagerotate($image, -90, 0),
            7 => imagerotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90, 0),
            8 => imagerotate($image, 90, 0),
        };
        if ($rotated instanceof \GdImage && $rotated !== $image) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    protected function flip(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** Centre-crops to a square, then resamples to $size × $size preserving transparency. */
    protected function squareResize(\GdImage $source, int $size): \GdImage
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);
        $srcX = (int) floor(($w - $side) / 2);
        $srcY = (int) floor(($h - $side) / 2);

        $target = imagecreatetruecolor($size, $size);
        if ($target === false) {
            throw ProfilePictureException::processingFailed();
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 255, 255, 255, 127);
        imagefill($target, 0, 0, $transparent);

        if (!imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $size, $size, $side, $side)) {
            imagedestroy($target);
            throw ProfilePictureException::processingFailed();
        }

        return $target;
    }

    /**
     * @return array{0: string|false, 1: string, 2: string}
     */
    protected function encode(\GdImage $image): array
    {
        $format = strtolower((string) ($this->config['output_format'] ?? 'webp'));
        $quality = (int) ($this->config['output_quality'] ?? 85);

        ob_start();
        try {
            if ($format === 'webp' && function_exists('imagewebp')) {
                imagewebp($image, null, $quality);
                $mime = 'image/webp';
                $ext = 'webp';
            } else {
                // PNG keeps transparency and is lossless; used when WEBP encoding is unavailable.
                imagepng($image, null, 6);
                $mime = 'image/png';
                $ext = 'png';
            }
        } finally {
            $contents = ob_get_clean();
        }

        return [$contents, $mime, $ext];
    }
}
