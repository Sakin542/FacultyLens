<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HealthCheckResult;
use App\Services\Admin\AlertEvaluator;
use App\Services\Admin\OperationTracker;
use App\Services\Admin\ServiceMonitor;
use App\Services\AuditLogService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/** Service health (live + recorded history), environment visibility and the scheduled-task list. Never returns secrets. */
class AdminSystemController extends Controller
{
    public function __construct(
        protected ServiceMonitor $monitor,
        protected AlertEvaluator $alerts,
        protected OperationTracker $operations,
        protected AuditLogService $audit,
    ) {}

    /** Latest recorded snapshot (cheap). Pass ?live=1 to probe now without recording. */
    public function health(Request $request): JsonResponse
    {
        $request->validate(['hours' => ['nullable', 'integer', 'in:6,24,72,168']]);
        $hours = (int) $request->query('hours', 24);

        if ($request->boolean('live')) {
            $services = array_values($this->monitor->checkAll());
            $checkedAt = now()->toIso8601String();
        } else {
            $latestAt = HealthCheckResult::query()->max('checked_at');
            $snapshot = $latestAt ? HealthCheckResult::query()->where('checked_at', $latestAt)->get()->keyBy('service') : collect();
            $services = collect(ServiceMonitor::SERVICES)->map(function ($def, $service) use ($snapshot) {
                $row = $snapshot->get($service);

                return [
                    'service' => $service,
                    'label' => $def['label'],
                    'group' => $def['group'],
                    'status' => $row?->status ?? ServiceMonitor::UNKNOWN,
                    'response_ms' => $row?->response_ms,
                    'detail' => collect($row?->detail ?? [])->except('summary')->all(),
                    'summary' => $row ? ($row->detail['summary'] ?? null) : 'Not checked yet. Run a check or start the scheduler.',
                ];
            })->values()->all();
            $checkedAt = $latestAt ? \Illuminate\Support\Carbon::parse($latestAt)->toIso8601String() : null;
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'checked_at' => $checkedAt,
                'live' => $request->boolean('live'),
                'services' => $services,
                'history' => $this->monitor->history($hours),
                'hours' => $hours,
                'scheduler_heartbeat_at' => $this->monitor->lastHeartbeat(),
            ],
        ]);
    }

    /** Probe every service now, record the snapshot and re-evaluate alert rules. */
    public function runCheck(Request $request): JsonResponse
    {
        $results = [];
        $op = $this->operations->run('health_check', 'Service health check', $request->user(), function () use (&$results) {
            $results = $this->monitor->record();
            $alerts = $this->alerts->evaluate($results);
            $unhealthy = collect($results)->whereIn('status', [ServiceMonitor::UNAVAILABLE, ServiceMonitor::DEGRADED])->count();

            return ['total' => count($results), 'success' => count($results) - $unhealthy, 'failure' => $unhealthy, 'summary' => ['alerts' => $alerts]];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Health check recorded.',
            'data' => ['operation' => $op->toPayload(), 'services' => array_values($results)],
        ]);
    }

    public function environment(): JsonResponse
    {
        $configured = fn ($v) => trim((string) $v) !== '';

        return response()->json([
            'status' => 'success',
            'data' => [
                'application' => [
                    'name' => config('app.name'),
                    'environment' => app()->environment(),
                    'version' => $this->version(),
                    'debug' => (bool) config('app.debug'),
                    'debug_warning' => config('app.debug') && app()->environment('production'),
                    'url' => config('app.url'),
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale'),
                    'php' => PHP_VERSION,
                    'laravel' => app()->version(),
                ],
                'drivers' => [
                    'database' => DB::connection()->getDriverName(),
                    'queue' => config('queue.default'),
                    'cache' => config('cache.default'),
                    'session' => config('session.driver'),
                    'mail' => config('mail.default'),
                    'broadcasting' => config('broadcasting.default'),
                    'filesystem' => config('filesystems.default'),
                ],
                // Booleans only: whether a value is present, never the value
                'configuration' => [
                    ['key' => 'ADMIN_EMAIL', 'label' => 'Administrator account lock', 'configured' => $configured(config('admin.email'))],
                    ['key' => 'AI_SERVICE_URL', 'label' => 'AI service address', 'configured' => $configured(config('services.ai.url'))],
                    ['key' => 'AI_SERVICE_API_KEY', 'label' => 'AI service shared key', 'configured' => $configured(config('services.ai.api_key'))],
                    ['key' => 'MAIL_*', 'label' => 'Outgoing e-mail', 'configured' => !in_array(config('mail.default'), ['log', 'array'], true)],
                    ['key' => 'REVERB_*', 'label' => 'Real-time broadcasting', 'configured' => !in_array(config('broadcasting.default'), ['null', 'log'], true)],
                    ['key' => 'GOOGLE_CLIENT_ID', 'label' => 'Google sign-in', 'configured' => $configured(config('admin.google.client_id'))],
                    ['key' => 'BACKUP_*', 'label' => 'Backup storage disk', 'configured' => $configured(config('admin.backups.disk'))],
                ],
                'ai' => [
                    'request_timeout_seconds' => (int) config('services.ai.timeout'),
                    'connect_timeout_seconds' => (int) config('services.ai.connect_timeout'),
                ],
                'migrations' => $this->migrationStatus(),
            ],
        ]);
    }

    /** Scheduled tasks registered in routes/console.php, with their next due time. */
    public function schedule(): JsonResponse
    {
        // Schedules live in routes/console.php, which only the console kernel loads; load it for HTTP requests too
        \Illuminate\Support\Facades\Artisan::all();
        $tasks = collect(app(Schedule::class)->events())->map(function ($event) {
            $command = (string) ($event->command ?? $event->description ?? '');
            // Strip the PHP binary and artisan path: show only the artisan command and its options
            if (preg_match("/artisan['\"]?\s+(.*)$/", $command, $m)) {
                $command = trim($m[1], " '\"");
            }
            $next = null;
            try {
                $next = $event->nextRunDate()->format(DATE_ATOM);
            } catch (Throwable) {
            }

            return [
                'command' => mb_substr($command, 0, 120),
                'description' => $event->description,
                'expression' => $event->expression,
                'timezone' => is_string($event->timezone) ? $event->timezone : (string) config('app.timezone'),
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'next_run_at' => $next,
            ];
        })->sortBy('next_run_at')->values();

        return response()->json(['status' => 'success', 'data' => ['tasks' => $tasks, 'heartbeat_at' => $this->monitor->lastHeartbeat(), 'timezone' => config('app.timezone')]]);
    }

    protected function version(): string
    {
        $configured = (string) config('admin.app_version', '');
        if ($configured !== '') {
            return $configured;
        }
        $head = base_path('../.git/HEAD');
        try {
            if (File::exists($head)) {
                $ref = trim(File::get($head));
                if (str_starts_with($ref, 'ref: ')) {
                    $refFile = base_path('../.git/'.substr($ref, 5));
                    $sha = File::exists($refFile) ? trim(File::get($refFile)) : null;

                    return $sha ? 'git '.substr($sha, 0, 7) : 'unversioned';
                }

                return 'git '.substr($ref, 0, 7);
            }
        } catch (Throwable) {
        }

        return 'unversioned';
    }

    protected function migrationStatus(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension())->all();
            $pending = array_values(array_diff($files, $ran));

            return [
                'ran' => count($ran),
                'files' => count($files),
                'pending' => $pending,
                'last_batch' => (int) DB::table('migrations')->max('batch'),
                'latest' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            ];
        } catch (Throwable $e) {
            return ['error' => class_basename($e)];
        }
    }
}
