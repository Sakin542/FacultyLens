<?php

namespace App\Services\Admin;

use App\Models\EmailDelivery;
use App\Models\HealthCheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Detailed, bounded service probes for the admin control center. Every service resolves to one explicit state:
 * healthy | degraded | unavailable | unconfigured | unknown. A service is never reported healthy unless a probe
 * actually succeeded. Details carry counts, versions and exception class names only (no hosts, credentials,
 * messages or payloads).
 */
class ServiceMonitor
{
    public const HEALTHY = 'healthy';
    public const DEGRADED = 'degraded';
    public const UNAVAILABLE = 'unavailable';
    public const UNCONFIGURED = 'unconfigured';
    public const UNKNOWN = 'unknown';

    public const HEARTBEAT_KEY = 'admin:monitor:heartbeat';

    public const SERVICES = [
        'database' => ['label' => 'Database', 'group' => 'Core'],
        'cache' => ['label' => 'Cache / Redis', 'group' => 'Core'],
        'storage' => ['label' => 'Private file storage', 'group' => 'Core'],
        'queue' => ['label' => 'Job queue', 'group' => 'Background processing'],
        'workers' => ['label' => 'Queue workers (Horizon)', 'group' => 'Background processing'],
        'scheduler' => ['label' => 'Task scheduler', 'group' => 'Background processing'],
        'ai_service' => ['label' => 'AI service (FastAPI)', 'group' => 'AI'],
        'ai_provider' => ['label' => 'Hosted AI provider', 'group' => 'AI'],
        'mail' => ['label' => 'E-mail delivery', 'group' => 'Messaging'],
        'broadcasting' => ['label' => 'Real-time broadcasting', 'group' => 'Messaging'],
    ];

    /** @return array<string, array{service: string, label: string, group: string, status: string, response_ms: ?int, detail: array, summary: string}> */
    public function checkAll(): array
    {
        $results = [];
        foreach (array_keys(self::SERVICES) as $service) {
            $results[$service] = $this->check($service);
        }

        return $results;
    }

    public function check(string $service): array
    {
        $started = hrtime(true);
        try {
            [$status, $detail, $summary] = match ($service) {
                'database' => $this->database(),
                'cache' => $this->cache(),
                'storage' => $this->storage(),
                'queue' => $this->queue(),
                'workers' => $this->workers(),
                'scheduler' => $this->scheduler(),
                'ai_service' => $this->aiService(),
                'ai_provider' => $this->aiProvider(),
                'mail' => $this->mail(),
                'broadcasting' => $this->broadcasting(),
                default => [self::UNKNOWN, [], 'No probe defined.'],
            };
        } catch (Throwable $e) {
            $status = self::UNAVAILABLE;
            $detail = ['error' => class_basename($e)];
            $summary = 'Probe failed ('.class_basename($e).').';
        }
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        return [
            'service' => $service,
            'label' => self::SERVICES[$service]['label'] ?? $service,
            'group' => self::SERVICES[$service]['group'] ?? 'Other',
            'status' => $status,
            // Timing is meaningful only for services that were actually contacted
            'response_ms' => in_array($status, [self::UNCONFIGURED, self::UNKNOWN], true) ? null : $ms,
            'detail' => $detail,
            'summary' => $summary,
        ];
    }

    /**
     * Run every probe and persist the snapshot. Only the scheduled admin:monitor run passes $heartbeat: a manual
     * check must not make a stopped scheduler look alive.
     */
    public function record(bool $heartbeat = false): array
    {
        $results = $this->checkAll();
        $now = now();
        foreach ($results as $r) {
            HealthCheckResult::query()->create([
                'service' => $r['service'],
                'status' => $r['status'],
                'response_ms' => $r['response_ms'],
                'detail' => $r['detail'] + ['summary' => $r['summary']],
                'checked_at' => $now,
            ]);
        }
        if ($heartbeat) {
            Cache::forever(self::HEARTBEAT_KEY, $now->toIso8601String());
        }

        return $results;
    }

    public function prune(): int
    {
        return HealthCheckResult::query()
            ->where('checked_at', '<', now()->subDays(max(1, (int) config('admin.monitor.history_days', 14))))
            ->delete();
    }

    /**
     * Per-service history over the window: last success, last failure, failure count, average latency and an
     * hourly availability series (share of healthy checks).
     */
    public function history(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $rows = HealthCheckResult::query()->where('checked_at', '>=', $since)->orderBy('checked_at')->get(['service', 'status', 'response_ms', 'checked_at']);
        $out = [];
        foreach (array_keys(self::SERVICES) as $service) {
            $own = $rows->where('service', $service);
            $failures = $own->whereIn('status', [self::UNAVAILABLE, self::DEGRADED]);
            $buckets = [];
            for ($h = $hours - 1; $h >= 0; $h--) {
                $start = now()->subHours($h + 1);
                $end = now()->subHours($h);
                $inBucket = $own->filter(fn ($r) => $r->checked_at > $start && $r->checked_at <= $end);
                $buckets[] = [
                    'hour' => $end->copy()->startOfHour()->toIso8601String(),
                    'checks' => $inBucket->count(),
                    'healthy' => $inBucket->where('status', self::HEALTHY)->count(),
                ];
            }
            $timed = $own->whereNotNull('response_ms');
            $out[$service] = [
                'checks' => $own->count(),
                'failures' => $failures->count(),
                'last_success_at' => $own->where('status', self::HEALTHY)->last()?->checked_at?->toIso8601String(),
                'last_failure_at' => $failures->last()?->checked_at?->toIso8601String(),
                'avg_response_ms' => $timed->count() ? (int) round($timed->avg('response_ms')) : null,
                'series' => $buckets,
            ];
        }

        return $out;
    }

    public function lastHeartbeat(): ?string
    {
        try {
            return Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    // ----------------------------------------------------------------------------------------------- probes

    protected function database(): array
    {
        DB::select('select 1');
        $driver = DB::connection()->getDriverName();
        $version = null;
        try {
            $version = match ($driver) {
                'mysql', 'mariadb' => DB::selectOne('select version() as v')->v ?? null,
                'sqlite' => DB::selectOne('select sqlite_version() as v')->v ?? null,
                default => null,
            };
        } catch (Throwable) {
            // version is informational
        }

        return [self::HEALTHY, ['driver' => $driver, 'version' => $version], "Connected ({$driver}).", ];
    }

    protected function cache(): array
    {
        $store = (string) config('cache.default');
        $key = 'admin:probe:'.bin2hex(random_bytes(4));
        $token = bin2hex(random_bytes(8));
        Cache::put($key, $token, 10);
        $ok = Cache::get($key) === $token;
        Cache::forget($key);
        if (!$ok) {
            return [self::DEGRADED, ['store' => $store], 'Cache write could not be read back.'];
        }
        if (in_array($store, ['array', 'null'], true)) {
            return [self::DEGRADED, ['store' => $store], "The {$store} cache does not persist between requests."];
        }

        return [self::HEALTHY, ['store' => $store], "Read/write OK ({$store})."];
    }

    protected function storage(): array
    {
        $diskName = (string) config('institutional_reports.disk', 'local');
        $disk = Storage::disk($diskName);
        $disk->put('health/.admin-probe', (string) time());
        $disk->delete('health/.admin-probe');

        $detail = ['disk' => $diskName];
        $status = self::HEALTHY;
        $summary = 'Read/write OK.';
        $root = storage_path('app');
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);
        if ($total && $free !== false) {
            $freePct = round($free / $total * 100, 1);
            $detail += ['free_bytes' => (int) $free, 'total_bytes' => (int) $total, 'free_percent' => $freePct];
            $summary = "Read/write OK · {$freePct}% free.";
            if ($freePct < (int) config('admin.monitor.storage_low_free_percent', 10)) {
                $status = self::DEGRADED;
                $summary = "Low free space ({$freePct}%).";
            }
        }

        return [$status, $detail, $summary];
    }

    protected function queue(): array
    {
        $driver = (string) config('queue.default');
        $failed24h = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        if ($driver === 'sync') {
            return [self::UNCONFIGURED, ['driver' => $driver, 'failed_24h' => $failed24h], 'Jobs run inline (QUEUE_CONNECTION=sync); no queue to monitor.'];
        }
        $pending = $driver === 'database' ? DB::table('jobs')->count() : Queue::size();
        $detail = ['driver' => $driver, 'pending' => $pending, 'failed_24h' => $failed24h];
        $backlog = (int) config('admin.alerts.queue_backlog', 200);
        if ($backlog > 0 && $pending > $backlog) {
            return [self::DEGRADED, $detail, "Backlog of {$pending} pending jobs."];
        }
        if ($failed24h > 0) {
            return [self::DEGRADED, $detail, "{$pending} pending · {$failed24h} failed in 24 h."];
        }

        return [self::HEALTHY, $detail, "{$pending} pending · no failures in 24 h."];
    }

    protected function workers(): array
    {
        $driver = (string) config('queue.default');
        if ($driver !== 'redis' || !interface_exists(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)) {
            return $driver === 'sync'
                ? [self::UNCONFIGURED, ['driver' => $driver], 'No workers needed for the sync queue.']
                : [self::UNKNOWN, ['driver' => $driver], 'Worker processes cannot be observed without Horizon on Redis.'];
        }
        $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
        if (empty($masters)) {
            return [self::UNAVAILABLE, ['supervisors' => 0], 'Horizon is not running.'];
        }
        $paused = collect($masters)->every(fn ($m) => ($m->status ?? '') === 'paused');

        return $paused
            ? [self::DEGRADED, ['supervisors' => count($masters), 'status' => 'paused'], 'Horizon is paused.']
            : [self::HEALTHY, ['supervisors' => count($masters), 'status' => 'running'], count($masters).' supervisor(s) running.'];
    }

    protected function scheduler(): array
    {
        $beat = $this->lastHeartbeat();
        if (!$beat) {
            return [self::UNKNOWN, [], 'No heartbeat recorded yet (admin:monitor has not run).'];
        }
        $age = (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($beat), true);
        $stale = (int) config('admin.monitor.heartbeat_stale_minutes', 15);

        return $age > $stale
            ? [self::UNAVAILABLE, ['last_heartbeat' => $beat, 'age_minutes' => $age], "Last heartbeat {$age} min ago."]
            : [self::HEALTHY, ['last_heartbeat' => $beat, 'age_minutes' => $age], "Heartbeat {$age} min ago."];
    }

    protected function aiService(): array
    {
        $url = rtrim((string) config('services.ai.url'), '/');
        if ($url === '') {
            return [self::UNCONFIGURED, [], 'AI_SERVICE_URL is not set.'];
        }
        $timeout = max(1, (int) config('admin.monitor.probe_timeout_seconds', 3));
        $res = Http::withOptions(['force_ip_resolve' => 'v4'])->connectTimeout($timeout)->timeout($timeout + 1)->get($url.'/health');
        if (!$res->ok()) {
            return [self::UNAVAILABLE, ['http_status' => $res->status()], "Health endpoint returned HTTP {$res->status()}."];
        }
        $detail = ['model' => is_string($res->json('model')) ? mb_substr($res->json('model'), 0, 120) : null, 'model_loaded' => (bool) $res->json('model_loaded')];
        if ($res->json('model_loaded') === false) {
            return [self::DEGRADED, $detail, 'Service is up but the model is not loaded.'];
        }

        return [self::HEALTHY, $detail, 'Service up, model loaded.'];
    }

    /**
     * The hosted provider (OpenRouter etc.) when the administrator selected it. Its failures are otherwise invisible:
     * every tool falls back to a local model and keeps working, with weaker results.
     */
    protected function aiProvider(): array
    {
        if ((string) app(FeatureFlagService::class)->get('ai_provider') !== 'hosted') {
            return [self::UNCONFIGURED, [], 'Local models are selected; the hosted provider is not in use.'];
        }
        $h = app(\App\Services\AiService::class)->providerHealth();
        $detail = [
            'calls_30m' => (int) ($h['calls'] ?? 0), 'failures_30m' => (int) ($h['failures'] ?? 0),
            'last_error' => is_string($h['last_error'] ?? null) ? mb_substr($h['last_error'], 0, 160) : null,
            'balance_remaining' => isset($h['credit']['remaining']) ? (float) $h['credit']['remaining'] : null,
        ];
        if (!($h['configured'] ?? false)) {
            return [self::UNAVAILABLE, $detail, 'Hosted is selected, but the AI service has no provider key or model (LLM_HOSTED_*). Every request uses the local models.'];
        }
        $calls = $detail['calls_30m'];
        $failures = $detail['failures_30m'];
        $threshold = max(1, (int) config('admin.alerts.ai_provider_failures', 3));
        if ($failures >= $threshold && $failures * 2 >= $calls) {
            return [self::UNAVAILABLE, $detail, "{$failures} of {$calls} hosted AI calls failed in the last 30 minutes ({$detail['last_error']}). Tools are falling back to the local models."];
        }
        if ($detail['balance_remaining'] !== null && $detail['balance_remaining'] <= (float) config('admin.alerts.ai_provider_min_balance', 0.5)) {
            return [self::DEGRADED, $detail, sprintf('Provider balance is $%.2f. Audio is refused below $0.50, and other requests may start failing; add credit.', $detail['balance_remaining'])];
        }
        if ($failures > 0) {
            return [self::DEGRADED, $detail, "{$failures} of {$calls} hosted AI calls failed in the last 30 minutes ({$detail['last_error']})."];
        }

        return [self::HEALTHY, $detail, $calls > 0 ? "{$calls} hosted AI calls in the last 30 minutes, none failed." : 'Configured; no hosted calls in the last 30 minutes.'];
    }

    protected function mail(): array
    {
        $mailer = (string) config('mail.default');
        $since = now()->subDay();
        $sent = EmailDelivery::query()->where('status', EmailDelivery::SENT)->where('sent_at', '>=', $since)->count();
        $failed = EmailDelivery::query()->where('status', EmailDelivery::FAILED)->where('failed_at', '>=', $since)->count();
        $detail = ['mailer' => $mailer, 'sent_24h' => $sent, 'failed_24h' => $failed];
        if (in_array($mailer, ['log', 'array'], true)) {
            return [self::UNCONFIGURED, $detail, "Mailer is \"{$mailer}\": messages are not delivered."];
        }
        if (in_array($mailer, ['smtp'], true)) {
            $host = (string) config('mail.mailers.smtp.host');
            $port = (int) config('mail.mailers.smtp.port');
            if ($host === '') {
                return [self::UNCONFIGURED, $detail, 'SMTP host is not set.'];
            }
            if (!$this->tcpReachable($host, $port)) {
                return [self::UNAVAILABLE, $detail, 'SMTP server is not reachable.'];
            }
        }
        if ($failed > 0 && $sent === 0) {
            return [self::DEGRADED, $detail, "{$failed} failed and none sent in 24 h."];
        }
        if ($failed > 0) {
            return [self::DEGRADED, $detail, "{$sent} sent · {$failed} failed in 24 h."];
        }

        return [self::HEALTHY, $detail, "Reachable · {$sent} sent in 24 h."];
    }

    protected function broadcasting(): array
    {
        $driver = (string) config('broadcasting.default');
        if (in_array($driver, ['null', 'log', ''], true)) {
            return [self::UNCONFIGURED, ['driver' => $driver], 'Real-time push is off; notifications still arrive through the database.'];
        }
        if ($driver === 'reverb') {
            $host = (string) config('broadcasting.connections.reverb.options.host');
            $port = (int) config('broadcasting.connections.reverb.options.port', 443);
            if ($host === '') {
                return [self::UNCONFIGURED, ['driver' => $driver], 'REVERB_HOST is not set.'];
            }

            return $this->tcpReachable($host, $port)
                ? [self::HEALTHY, ['driver' => $driver], 'Reverb server reachable.']
                : [self::UNAVAILABLE, ['driver' => $driver], 'Reverb server is not reachable.'];
        }

        return [self::UNKNOWN, ['driver' => $driver], "No probe for the {$driver} broadcaster."];
    }

    protected function tcpReachable(string $host, int $port): bool
    {
        $timeout = max(1, (int) config('admin.monitor.probe_timeout_seconds', 3));
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
