<?php

namespace App\Services\Admin;

use App\Models\DatabaseBackup;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Gzipped database snapshots in private storage: mysqldump for MySQL/MariaDB, a file copy for SQLite.
 * Each run is recorded in database_backups; completed backups beyond config('admin.backups.keep') are pruned.
 */
class DatabaseBackupService
{
    public function run(string $trigger = 'manual', ?User $by = null): DatabaseBackup
    {
        $connection = config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $driver = (string) ($config['driver'] ?? 'unknown');
        $filename = sprintf('facultylens-%s-%s.sql.gz', now()->format('Ymd-His'), bin2hex(random_bytes(3)));
        $relative = trim((string) config('admin.backups.directory', 'backups'), '/').'/'.$filename;

        $backup = DatabaseBackup::query()->create([
            'filename' => $filename,
            'disk' => $this->diskName(),
            'path' => $relative,
            'status' => DatabaseBackup::STATUS_RUNNING,
            'trigger' => $trigger === 'scheduled' ? 'scheduled' : 'manual',
            'driver' => $driver,
            'triggered_by' => $by?->id,
            'started_at' => now(),
        ]);

        $disk = $this->disk();
        $disk->makeDirectory(dirname($relative));
        $absolute = $disk->path($relative);

        try {
            match ($driver) {
                'mysql', 'mariadb' => $this->dumpMysql($config, $absolute),
                'sqlite' => $this->copySqlite($config, $absolute),
                default => throw new RuntimeException("Backups are not supported for the {$driver} driver."),
            };

            $backup->forceFill([
                'status' => DatabaseBackup::STATUS_COMPLETED,
                'size_bytes' => (int) @filesize($absolute),
                'checksum_sha256' => hash_file('sha256', $absolute) ?: null,
                'completed_at' => now(),
            ])->save();
            $this->archiveFiles($backup, $relative);
            $this->prune();
        } catch (Throwable $e) {
            @unlink($absolute);
            Log::error('Database backup failed: '.$e->getMessage());
            $backup->forceFill([
                'status' => DatabaseBackup::STATUS_FAILED,
                'error' => mb_substr($this->safeError($e, $config), 0, 255),
                'completed_at' => now(),
            ])->save();
        }

        return $backup->refresh();
    }

    /**
     * ZIP the uploaded files next to the dump (<name>.files.zip). The database dump is the part that must not fail, so
     * a problem here is recorded on the backup and logged, and the backup stays usable for the database.
     */
    protected function archiveFiles(DatabaseBackup $backup, string $dumpRelative): void
    {
        if (!config('admin.backups.include_files', true) || !class_exists(ZipArchive::class)) {
            return;
        }
        $source = Storage::disk('local');
        $files = [];
        foreach ((array) config('admin.backups.file_folders', []) as $folder) {
            if ($source->exists($folder)) {
                foreach ($source->allFiles($folder) as $file) {
                    $files[] = $file;
                }
            }
        }
        if ($files === []) {
            return;
        }
        $relative = preg_replace('/\.sql\.gz$/', '', $dumpRelative).'.files.zip';
        $absolute = $this->disk()->path($relative);
        try {
            $zip = new ZipArchive();
            if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('cannot create the ZIP archive');
            }
            foreach ($files as $file) {
                $zip->addFile($source->path($file), $file);
            }
            if (!$zip->close()) {
                throw new RuntimeException('cannot write the ZIP archive');
            }
            $backup->forceFill(['files_path' => $relative, 'files_size_bytes' => (int) @filesize($absolute), 'files_count' => count($files)])->save();
        } catch (Throwable $e) {
            @unlink($absolute);
            Log::error('Backup files archive failed: '.$e->getMessage());
            $backup->forceFill(['error' => mb_substr('Database saved; the files archive failed ('.class_basename($e).').', 0, 255)])->save();
        }
    }

    /** Due when no successful backup is newer than the interval (half an hour of slack so hourly checks do not drift). */
    public function isDue(): bool
    {
        $latest = $this->latestCompleted();
        $hours = max(1, (int) config('admin.backups.interval_hours', 24));

        return !$latest || $latest->completed_at === null || $latest->completed_at->lte(now()->subHours($hours)->addMinutes(30));
    }

    public function filesPath(DatabaseBackup $backup): ?string
    {
        if (!$backup->files_path) {
            return null;
        }
        $disk = Storage::disk($backup->disk);

        return $disk->exists($backup->files_path) ? $disk->path($backup->files_path) : null;
    }

    public function latestCompleted(): ?DatabaseBackup
    {
        return DatabaseBackup::query()->where('status', DatabaseBackup::STATUS_COMPLETED)->latest('completed_at')->first();
    }

    /**
     * Integrity check: the file still matches the checksum recorded at creation and decompresses end to end.
     * This proves the archive is intact, not that a restore would succeed against the current schema.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function verify(DatabaseBackup $backup): array
    {
        $path = $backup->status === DatabaseBackup::STATUS_COMPLETED ? $this->absolutePath($backup) : null;
        $reason = null;
        if (!$path) {
            $reason = 'The backup file is missing from storage.';
        } elseif ($backup->checksum_sha256 && !hash_equals($backup->checksum_sha256, (string) hash_file('sha256', $path))) {
            $reason = 'The file no longer matches the checksum recorded when it was created.';
        } else {
            $gz = @gzopen($path, 'rb');
            if ($gz === false) {
                $reason = 'The file cannot be opened as gzip.';
            } else {
                $bytes = 0;
                while (!gzeof($gz)) {
                    $chunk = gzread($gz, 1 << 20);
                    if ($chunk === false) {
                        $reason = 'The gzip stream is corrupt.';
                        break;
                    }
                    $bytes += strlen($chunk);
                }
                gzclose($gz);
                if ($reason === null && $bytes === 0) {
                    $reason = 'The archive decompresses to an empty file.';
                }
            }
        }

        if ($reason === null && $backup->files_path) {
            $files = $this->filesPath($backup);
            $zip = new ZipArchive();
            if (!$files) {
                $reason = 'The uploaded-files archive is missing from storage.';
            } elseif ($zip->open($files, ZipArchive::CHECKCONS) !== true) {
                $reason = 'The uploaded-files archive is damaged.';
            } else {
                $count = $zip->numFiles;
                $zip->close();
                if ($count !== (int) $backup->files_count) {
                    $reason = "The uploaded-files archive holds {$count} files; {$backup->files_count} were recorded.";
                }
            }
        }

        $backup->forceFill([
            'verification_status' => $reason === null ? 'verified' : 'failed',
            'verified_at' => now(),
            'checksum_sha256' => $backup->checksum_sha256 ?? ($reason === null && $path ? hash_file('sha256', $path) : null),
        ])->save();

        return ['ok' => $reason === null, 'reason' => $reason];
    }

    public function absolutePath(DatabaseBackup $backup): ?string
    {
        $disk = Storage::disk($backup->disk);

        return $disk->exists($backup->path) ? $disk->path($backup->path) : null;
    }

    public function delete(DatabaseBackup $backup): void
    {
        Storage::disk($backup->disk)->delete(array_filter([$backup->path, $backup->files_path]));
        $backup->delete();
    }

    /** Keep the newest N completed backups; delete older files and their records. */
    public function prune(): int
    {
        $keep = max(1, (int) config('admin.backups.keep', 14));
        $stale = DatabaseBackup::query()
            ->where('status', DatabaseBackup::STATUS_COMPLETED)
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->skip($keep)->take(PHP_INT_MAX)->get();
        foreach ($stale as $backup) {
            $this->delete($backup);
        }

        return $stale->count();
    }

    protected function dumpMysql(array $config, string $absolute): void
    {
        $gz = gzopen($absolute, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Cannot open the backup file for writing.');
        }

        $command = [
            (string) config('admin.backups.mysqldump_binary', 'mysqldump'),
            '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? 'root'),
            (string) ($config['database'] ?? ''),
        ];

        $stderr = '';
        try {
            // The password travels in the environment, never on the command line (visible in `ps`)
            $result = Process::timeout((int) config('admin.backups.timeout_seconds', 600))
                ->env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])
                ->run($command, function (string $type, string $buffer) use ($gz, &$stderr) {
                    if ($type === 'out') {
                        gzwrite($gz, $buffer);
                    } else {
                        $stderr .= $buffer;
                    }
                });
        } finally {
            gzclose($gz);
        }

        if (!$result->successful()) {
            throw new RuntimeException('mysqldump exited with code '.$result->exitCode().': '.trim($stderr ?: $result->errorOutput()));
        }
        if ((int) @filesize($absolute) <= 20) {
            throw new RuntimeException('mysqldump produced an empty backup.');
        }
    }

    protected function copySqlite(array $config, string $absolute): void
    {
        $source = (string) ($config['database'] ?? '');
        if ($source === '' || $source === ':memory:' || !is_file($source)) {
            throw new RuntimeException('The SQLite database is in memory or missing; nothing to back up.');
        }
        $in = fopen($source, 'rb');
        $gz = gzopen($absolute, 'wb6');
        try {
            while (!feof($in)) {
                gzwrite($gz, (string) fread($in, 1 << 20));
            }
        } finally {
            fclose($in);
            gzclose($gz);
        }
    }

    protected function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    protected function diskName(): string
    {
        return (string) config('admin.backups.disk', 'local');
    }

    /** Admin-visible error text with the database password scrubbed. */
    protected function safeError(Throwable $e, array $config): string
    {
        $message = $e->getMessage();
        $password = (string) ($config['password'] ?? '');

        return $password !== '' ? str_replace($password, '***', $message) : $message;
    }
}
