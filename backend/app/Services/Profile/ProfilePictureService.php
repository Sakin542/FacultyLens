<?php

namespace App\Services\Profile;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the upload → validate → normalise → private store → DB reference → cleanup lifecycle of faculty avatars.
 *
 * Failure ordering guarantees:
 *  - processing or storage failure  → database untouched, previous picture untouched
 *  - database failure               → the freshly written file is removed (no orphans)
 *  - old file is deleted only AFTER the new reference has been committed
 */
class ProfilePictureService
{
    /** @var array<string, string> */
    public const MIME_BY_EXTENSION = [
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(
        protected ProfileImageProcessor $processor,
        protected AuditLogService $audit,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('profile_picture.disk', 'local');
    }

    public function userDirectory(User $user): string
    {
        return trim((string) config('profile_picture.directory', 'profile-pictures'), '/') . '/' . $user->id;
    }

    /**
     * @throws ProfilePictureException
     */
    public function upload(User $user, UploadedFile $file): User
    {
        $previousPath = $user->getAttribute('profile_picture_path');

        try {
            $image = $this->processor->process($file->getRealPath() ?: $file->getPathname());
        } catch (ProfilePictureException $e) {
            $this->auditFailure($user, $e->reason, $file);
            throw $e;
        } catch (Throwable $e) {
            Log::error('Profile picture processing crashed: ' . $e->getMessage());
            $this->auditFailure($user, 'processing_error', $file);
            throw ProfilePictureException::processingFailed();
        }

        $newPath = $this->userDirectory($user) . '/' . Str::uuid()->toString() . '.' . $image->extension;

        try {
            $written = $this->disk()->put($newPath, $image->contents, ['visibility' => 'private']);
        } catch (Throwable $e) {
            Log::error('Profile picture storage failed: ' . $e->getMessage());
            $written = false;
        }
        if ($written === false) {
            $this->auditFailure($user, 'storage_failed', $file);
            throw ProfilePictureException::storageFailed();
        }

        try {
            DB::transaction(function () use ($user, $newPath) {
                $user->forceFill([
                    'profile_picture_path' => $newPath,
                    'profile_picture_updated_at' => now(),
                ])->save();
            });
        } catch (Throwable $e) {
            Log::error('Profile picture database update failed: ' . $e->getMessage());
            $this->deleteQuietly($newPath);
            $this->auditFailure($user, 'database_failed', $file);
            throw ProfilePictureException::storageFailed();
        }

        // Reference is committed: retire the previous file and any orphans in this user's folder.
        $this->cleanupUserDirectory($user, $newPath);

        $this->audit->log($previousPath ? 'PROFILE_PICTURE_REPLACED' : 'PROFILE_PICTURE_UPLOADED', $user, $user->id, [
            'source_mime' => $image->sourceMimeType,
            'source_size_bytes' => $file->getSize(),
            'source_width' => $image->sourceWidth,
            'source_height' => $image->sourceHeight,
            'stored_format' => $image->extension,
            'stored_size_bytes' => $image->size(),
            'normalized' => $image->normalized,
        ], $user);

        return $user->refresh();
    }

    /**
     * Idempotent: a missing file or an already-empty reference still leaves the record clean.
     */
    public function remove(User $user): User
    {
        $previousPath = $user->getAttribute('profile_picture_path');
        $hadFile = $previousPath !== null && $this->safeExists($previousPath);

        if ($previousPath !== null) {
            DB::transaction(function () use ($user) {
                $user->forceFill([
                    'profile_picture_path' => null,
                    'profile_picture_updated_at' => now(),
                ])->save();
            });
        }

        $this->cleanupUserDirectory($user, null);

        if ($previousPath !== null) {
            $this->audit->log('PROFILE_PICTURE_REMOVED', $user, $user->id, ['had_file' => $hadFile], $user);
        }

        return $user->refresh();
    }

    /**
     * Resolves the stored picture for streaming; null when the user has none or the file is missing.
     *
     * @return array{path: string, mime: string, size: int|null}|null
     */
    public function resolve(User $user): ?array
    {
        $path = $user->getAttribute('profile_picture_path');
        if (!is_string($path) || $path === '' || !$this->isOwnedPath($user, $path)) {
            return null;
        }
        if (!$this->safeExists($path)) {
            Log::warning('Profile picture reference points to a missing file.', ['user_id' => $user->id]);

            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return [
            'path' => $path,
            'mime' => self::MIME_BY_EXTENSION[$ext] ?? 'application/octet-stream',
            'size' => $this->safeSize($path),
        ];
    }

    /** A reference is only trusted when it lives inside the user's own avatar folder (no traversal, no foreign paths). */
    public function isOwnedPath(User $user, string $path): bool
    {
        $prefix = $this->userDirectory($user) . '/';
        if (!str_starts_with($path, $prefix) || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset(self::MIME_BY_EXTENSION[$ext]);
    }

    // ------------------------------------------------------------------ internals

    /** Removes every file in the user's avatar folder except $keep (handles stale replacements and orphans). */
    protected function cleanupUserDirectory(User $user, ?string $keep): void
    {
        try {
            $dir = $this->userDirectory($user);
            foreach ($this->disk()->files($dir) as $file) {
                if ($keep !== null && $file === $keep) {
                    continue;
                }
                $this->disk()->delete($file);
            }
            if ($keep === null && $this->disk()->exists($dir)) {
                $this->disk()->deleteDirectory($dir);
            }
        } catch (Throwable $e) {
            Log::warning('Profile picture cleanup skipped: ' . $e->getMessage(), ['user_id' => $user->id]);
        }
    }

    protected function deleteQuietly(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            Log::warning('Could not remove orphaned profile picture: ' . $e->getMessage());
        }
    }

    protected function safeExists(string $path): bool
    {
        try {
            return $this->disk()->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    protected function safeSize(string $path): ?int
    {
        try {
            return (int) $this->disk()->size($path);
        } catch (Throwable) {
            return null;
        }
    }

    protected function auditFailure(User $user, string $reason, UploadedFile $file): void
    {
        try {
            $this->audit->log('PROFILE_PICTURE_UPLOAD_FAILED', $user, $user->id, [
                'reason' => $reason,
                'declared_mime' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ], $user);
        } catch (Throwable $e) {
            Log::warning('Could not audit profile picture failure: ' . $e->getMessage());
        }
    }
}
