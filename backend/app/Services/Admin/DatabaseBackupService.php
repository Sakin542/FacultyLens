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
        Storage::disk($backup->disk)->delete($backup->path);
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
