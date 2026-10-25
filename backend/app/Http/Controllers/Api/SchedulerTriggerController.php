<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs `schedule:run` when called by an external cron service (e.g. cron-job.org) on hosts that cannot run a
 * scheduler process, such as Render's free plan. Disabled unless SCHEDULER_TOKEN is set; the token travels in the
 * X-Scheduler-Token header. Answers 404 to anything else so the endpoint does not advertise itself.
 */
class SchedulerTriggerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('services.scheduler.token');
        if ($token === '' || !hash_equals($token, (string) $request->header('X-Scheduler-Token'))) {
            abort(404);
        }

        // Cron services stop waiting after ~30 s; due tasks (backups, scans) must still finish
        ignore_user_abort(true);
        set_time_limit(0);

        $started = microtime(true);
        $exit = Artisan::call('schedule:run');
        $ms = (int) round((microtime(true) - $started) * 1000);
        Log::info('scheduler.trigger', ['exit_code' => $exit, 'duration_ms' => $ms]);

        return response()->json(['status' => $exit === 0 ? 'success' : 'error', 'exit_code' => $exit, 'duration_ms' => $ms], $exit === 0 ? 200 : 500);
    }
}
