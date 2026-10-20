<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Liveness (`/api/health`) and readiness (`/api/health/ready`) probes.
 * Public responses are coarse ("ok" / "error" per dependency) and never include hosts, versions,
 * credentials or error text; authenticated admins may request `?details=1` for diagnostics.
 */
class HealthController extends Controller
{
    /** Liveness: the PHP process answers and the primary database connection can be opened. */
    public function check(): JsonResponse
    {
        $db = $this->probe(fn () => DB::connection()->getPdo());

        return response()->json([
            'status' => $db['ok'] ? 'ok' : 'degraded',
            'message' => $db['ok'] ? 'FacultyLens API is running' : 'FacultyLens API running with issues',
            'service' => 'Laravel Backend',
            'database' => $db['ok'] ? 'connected' : 'error',
            'timestamp' => now()->toIso8601String(),
        ], $db['ok'] ? 200 : 503);
    }

    /** Readiness: every dependency the request path needs (database, cache, queue, private storage, AI service). */
    public function ready(Request $request): JsonResponse
    {
        // Load balancers poll frequently; probe results are shared for a few seconds (never the admin details flag)
        $checks = Cache::remember('health:ready:checks', 5, fn () => $this->runChecks());

        // The AI service is required for analysis features, not for serving authenticated reads
        $critical = ['database', 'cache', 'storage'];
        $ready = collect($critical)->every(fn ($k) => $checks[$k]['ok']);
        $status = $ready ? (collect($checks)->every(fn ($c) => $c['ok']) ? 'ready' : 'degraded') : 'not_ready';

        $user = $request->user('sanctum');
        $showDetails = $request->boolean('details') && $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        $components = collect($checks)->map(fn ($c) => $showDetails ? ['status' => $c['ok'] ? 'ok' : 'error', 'detail' => $c['detail']] : ($c['ok'] ? 'ok' : 'error'))->all();

        return response()->json([
            'status' => $status,
            'service' => 'Laravel Backend',
            'components' => $components,
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    /** @return array<string, array{ok: bool, detail: mixed}> */
    protected function runChecks(): array
    {
        return app(\App\Services\Admin\SystemHealthService::class)->checks();
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
