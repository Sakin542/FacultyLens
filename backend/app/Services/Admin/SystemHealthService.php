<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Dependency probes shared by the public readiness endpoint (coarse) and the admin dashboard (detailed).
 * Details never include hosts, credentials or exception messages, only counts and exception class names.
 */
class SystemHealthService
{
    /** @return array<string, array{ok: bool, detail: mixed}> */
    public function checks(bool $includeWorkers = false): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'cache' => $this->probe(function () {
                $key = 'health:ready:'.bin2hex(random_bytes(4));
                // String token: Redis/predis returns numerics as strings, so a strict int compare would always fail (STEP 43).
                $token = bin2hex(random_bytes(8));
                Cache::put($key, $token, 10);
                if (Cache::get($key) !== $token) {
                    throw new \RuntimeException('cache read-back failed');
                }
                Cache::forget($key);
            }),
            'storage' => $this->probe(function () {
                $disk = Storage::disk(config('institutional_reports.disk', 'local'));
                $disk->put('health/.probe', (string) time());
                $disk->delete('health/.probe');
            }),
            'queue' => $this->probe(function () {
                $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
                $pending = null;
                try {
                    $pending = config('queue.default') === 'database' ? DB::table('jobs')->count() : Queue::size();
                } catch (Throwable) {
                    // queue backend unreachable: reported through the horizon/cache probes
                }

                return ['pending' => $pending, 'failed_24h' => $failed];
            }),
            'ai_service' => $this->probe(function () {
                $res = Http::withOptions(['force_ip_resolve' => 'v4'])->connectTimeout(2)->timeout(4)->get(rtrim((string) config('services.ai.url'), '/').'/health');
                if (! $res->ok()) {
                    throw new \RuntimeException('ai health '.$res->status());
                }
                if (($res->json('model_loaded') ?? null) === false) {
                    throw new \RuntimeException('model not loaded');
                }

                return ['model_loaded' => (bool) $res->json('model_loaded')];
            }),
        ];

        if ($includeWorkers) {
            $checks['workers'] = $this->probe(fn () => $this->horizonStatus());
        }

        return $checks;
    }

    /** Horizon supervisor state (running / paused / inactive) from its Redis repositories. */
    protected function horizonStatus(): array
    {
        if (config('queue.default') !== 'redis' || !interface_exists(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)) {
            return ['status' => 'not_used', 'supervisors' => 0];
        }
        $masters = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
        if (empty($masters)) {
            throw new \RuntimeException('horizon inactive');
        }
        $paused = collect($masters)->every(fn ($m) => ($m->status ?? '') === 'paused');

        return ['status' => $paused ? 'paused' : 'running', 'supervisors' => count($masters)];
    }

    /** @return array{ok: bool, detail: mixed} */
    protected function probe(callable $fn): array
    {
        try {
            $detail = $fn();

            return ['ok' => true, 'detail' => is_array($detail) ? $detail : null];
        } catch (Throwable $e) {
            // Only the exception class is kept for admin diagnostics; messages may contain hosts or credentials
            return ['ok' => false, 'detail' => class_basename($e)];
        }
    }
}
