<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DatabaseBackup;
use App\Services\Admin\DatabaseBackupService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminBackupController extends Controller
{
    public function __construct(protected DatabaseBackupService $backups, protected AuditLogService $audit) {}

    public function index(): JsonResponse
    {
        $items = DatabaseBackup::query()->with('triggeredBy:id,name')->latest('id')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => $items->map(fn (DatabaseBackup $b) => $b->toPayload())->all(),
            'meta' => [
                'keep' => (int) config('admin.backups.keep', 14),
                'schedule_time' => (string) config('admin.backups.schedule_time', '02:00'),
                'last_completed' => $this->backups->latestCompleted()?->toPayload(),
                'disk' => (string) config('admin.backups.disk', 'local'),
                'driver' => \Illuminate\Support\Facades\DB::connection()->getDriverName(),
                'failed_30d' => DatabaseBackup::query()->where('status', DatabaseBackup::STATUS_FAILED)->where('created_at', '>=', now()->subDays(30))->count(),
                'restore' => [
                    'available_in_panel' => false,
                    'command' => 'php artisan backup:restore {id}',
                    'reason' => 'Restoring replaces every table in the live database, so it runs only from the server console with an explicit confirmation.',
                ],
            ],
        ]);
    }

    /** Runs synchronously: even a large dev database dumps in seconds, and the admin wants the result immediately. */
    public function store(Request $request): JsonResponse
    {
        if (DatabaseBackup::query()->where('status', DatabaseBackup::STATUS_RUNNING)->where('started_at', '>=', now()->subMinutes(15))->exists()) {
            return response()->json(['status' => 'error', 'message' => 'A backup is already running.'], 409);
        }

        $backup = $this->backups->run('manual', $request->user());
        $this->audit->log('ADMIN_BACKUP_CREATED', $backup, $backup->id, ['status' => $backup->status], $request->user());

        if ($backup->status !== DatabaseBackup::STATUS_COMPLETED) {
            return response()->json([
                'status' => 'error',
                'message' => 'The backup failed: '.($backup->error ?: 'unknown error'),
                'data' => $backup->load('triggeredBy:id,name')->toPayload(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Backup created.',
            'data' => $backup->load('triggeredBy:id,name')->toPayload(),
        ], 201);
    }

    /** Recompute the checksum and decompress the archive end to end (does not touch the live database). */
    public function verify(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $result = $this->backups->verify($backup);
        $this->audit->log('ADMIN_BACKUP_VERIFIED', $backup, $backup->id, ['ok' => $result['ok']], $request->user());

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'error',
            'message' => $result['ok'] ? 'Backup verified: checksum matches and the archive decompresses completely.' : 'Verification failed: '.$result['reason'],
            'data' => $backup->refresh()->load('triggeredBy:id,name')->toPayload(),
        ], $result['ok'] ? 200 : 422);
    }

    public function download(Request $request, DatabaseBackup $backup): BinaryFileResponse|JsonResponse
    {
        $path = $backup->status === DatabaseBackup::STATUS_COMPLETED ? $this->backups->absolutePath($backup) : null;
        if (!$path) {
            return response()->json(['status' => 'error', 'message' => 'This backup file is no longer available.'], 404);
        }
        $this->audit->log('ADMIN_BACKUP_DOWNLOADED', $backup, $backup->id, null, $request->user());

        return response()->download($path, $backup->filename, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $this->backups->delete($backup);
        $this->audit->log('ADMIN_BACKUP_DELETED', 'DatabaseBackup', $backup->id, ['filename' => $backup->filename], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Backup deleted.']);
    }
}
