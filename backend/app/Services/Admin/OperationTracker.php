<?php

namespace App\Services\Admin;

use App\Models\AdminOperation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Records administrative operations in the task center. Status only changes when the work itself reports back:
 * `run()` marks the operation completed after the callback returns, or failed with a safe error summary.
 */
class OperationTracker
{
    public function start(string $type, string $label, ?User $by = null, ?int $total = null): AdminOperation
    {
        return AdminOperation::query()->create([
            'type' => $type,
            'label' => mb_substr($label, 0, 160),
            'status' => AdminOperation::RUNNING,
            'initiated_by' => $by?->id,
            'total_count' => $total,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  callable(AdminOperation): array{success?: int, failure?: int, total?: int, summary?: array, file?: array{name: string, contents: string}}  $work
     */
    public function run(string $type, string $label, ?User $by, callable $work): AdminOperation
    {
        $op = $this->start($type, $label, $by);
        try {
            $result = (array) $work($op);
            $this->complete($op, $result);
        } catch (Throwable $e) {
            $this->fail($op, $e);
        }

        return $op->refresh();
    }

    public function complete(AdminOperation $op, array $result = []): void
    {
        $attributes = [
            'status' => AdminOperation::COMPLETED,
            'success_count' => $result['success'] ?? null,
            'failure_count' => $result['failure'] ?? null,
            'total_count' => $result['total'] ?? $op->total_count,
            'summary' => $result['summary'] ?? null,
            'completed_at' => now(),
        ];
        if (isset($result['file'])) {
            $disk = (string) config('admin.exports.disk', 'local');
            $path = trim((string) config('admin.exports.directory', 'admin-exports'), '/').'/'.$op->id.'-'.bin2hex(random_bytes(6)).'-'.$result['file']['name'];
            Storage::disk($disk)->put($path, $result['file']['contents']);
            $attributes += [
                'result_disk' => $disk,
                'result_path' => $path,
                'result_filename' => $result['file']['name'],
                'result_expires_at' => now()->addHours(max(1, (int) config('admin.exports.ttl_hours', 24))),
            ];
        }
        $op->forceFill($attributes)->save();
    }

    public function fail(AdminOperation $op, Throwable|string $error): void
    {
        $message = $error instanceof Throwable
            ? ($error instanceof AdminOperationException ? $error->getMessage() : 'Failed ('.class_basename($error).').')
            : $error;
        $op->forceFill(['status' => AdminOperation::FAILED, 'error' => mb_substr($message, 0, 255), 'completed_at' => now()])->save();
    }

    /** Delete expired result files; the operation rows are kept as history. */
    public function purgeExpiredResults(): int
    {
        $expired = AdminOperation::query()->whereNotNull('result_path')->where('result_expires_at', '<', now())->get();
        foreach ($expired as $op) {
            Storage::disk($op->result_disk ?: 'local')->delete($op->result_path);
            $op->forceFill(['result_path' => null])->save();
        }

        return $expired->count();
    }
}
