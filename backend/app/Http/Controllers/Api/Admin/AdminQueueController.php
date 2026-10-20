<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\FailedJobInspector;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Queue operations: depth per queue, failure trends and failed-job inspection. Payloads are never returned, jobs
 * cannot be constructed from the UI, and retry is limited to job classes on FailedJobInspector's safe list.
 */
class AdminQueueController extends Controller
{
    public function __construct(protected AuditLogService $audit) {}

    public function summary(): JsonResponse
    {
        $driver = (string) config('queue.default');
        $queues = $this->queueNames();
        $depth = [];
        foreach ($queues as $name) {
            try {
                $depth[] = ['queue' => $name, 'pending' => $driver === 'database' ? DB::table('jobs')->where('queue', $name)->count() : ($driver === 'sync' ? 0 : Queue::size($name))];
            } catch (Throwable $e) {
                $depth[] = ['queue' => $name, 'pending' => null, 'error' => class_basename($e)];
            }
        }

        $since = now()->subDays(14)->startOfDay();
        $daily = DB::table('failed_jobs')->where('failed_at', '>=', $since)->selectRaw('DATE(failed_at) as d, COUNT(*) as n')->groupBy(DB::raw('DATE(failed_at)'))->pluck('n', 'd');
        $trend = [];
        for ($i = 0; $i < 15; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $trend[] = ['date' => $date, 'total' => (int) ($daily[$date] ?? 0)];
        }

        $byJob = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(30))->get(['payload'])
            ->map(fn ($r) => (string) (json_decode((string) $r->payload, true)['displayName'] ?? 'Unknown job'))
            ->countBy()->sortDesc()->take(10)->map(fn ($n, $job) => ['job' => class_basename($job), 'count' => $n])->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'driver' => $driver,
                'horizon_available' => $driver === 'redis' && class_exists(\Laravel\Horizon\Horizon::class),
                'horizon_path' => '/'.trim((string) config('horizon.path', 'horizon'), '/'),
                'queues' => $depth,
                'failed_total' => DB::table('failed_jobs')->count(),
                'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
                'failed_trend' => $trend,
                'failed_by_job' => $byJob,
                'retry_policy' => collect(FailedJobInspector::RETRY_POLICY)->map(fn ($p, $class) => ['job' => class_basename($class)] + $p)->values(),
            ],
        ]);
    }

    public function failed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:64'],
            'job' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = DB::table('failed_jobs')->orderByDesc('failed_at')->orderByDesc('id');
        if (!empty($validated['queue'])) {
            $query->where('queue', $validated['queue']);
        }
        if (!empty($validated['job'])) {
            $query->where('payload', 'like', '%"displayName":"'.addcslashes('App\\Jobs\\'.class_basename($validated['job']), '\\').'"%');
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn ($row) => FailedJobInspector::summarize($row))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'This failed job no longer exists.'], 404);
        }
        $job = FailedJobInspector::summarize($row);
        if (!$job['retryable']) {
            return response()->json(['status' => 'error', 'message' => 'This job type is not safe to retry from the panel. '.$job['retry_policy']], 422);
        }
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->log('ADMIN_JOB_RETRIED', 'FailedJob', (int) $row->id, ['job' => $job['job_name'], 'queue' => $job['queue']], $request->user());

        return response()->json(['status' => 'success', 'message' => "{$job['job_name']} was pushed back onto the {$job['queue']} queue."]);
    }

    public function forget(Request $request, string $uuid): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'This failed job no longer exists.'], 404);
        }
        $job = FailedJobInspector::summarize($row);
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();
        $this->audit->log('ADMIN_JOB_DISMISSED', 'FailedJob', (int) $row->id, ['job' => $job['job_name'], 'queue' => $job['queue']], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Failed job removed from the list.']);
    }

    /** @return list<string> */
    protected function queueNames(): array
    {
        $names = ['default'];
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $q) {
                $names[] = (string) $q;
            }
        }
        foreach (['notifications.queue.name', 'email.queue.name'] as $key) {
            if (is_string(config($key)) && config($key) !== '') {
                $names[] = (string) config($key);
            }
        }
        $names = array_merge($names, DB::table('failed_jobs')->distinct()->limit(20)->pluck('queue')->all());

        return array_values(array_unique(array_filter($names)));
    }
}
