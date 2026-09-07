<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Health check endpoint for FacultyLens Backend API.
     */
    public function check(): JsonResponse
    {
        $databaseStatus = 'disconnected';
        $isHealthy = true;

        try {
            DB::connection()->getPdo();
            $databaseStatus = 'connected';
        } catch (Throwable $e) {
            $databaseStatus = 'error';
            $isHealthy = false;
        }

        $payload = [
            'status' => $isHealthy ? 'ok' : 'degraded',
            'message' => $isHealthy ? 'FacultyLens API is running' : 'FacultyLens API running with issues',
            'service' => 'Laravel Backend',
            'database' => $databaseStatus,
            'timestamp' => now()->toIso8601String(),
        ];

        return response()->json($payload, $isHealthy ? 200 : 503);
    }
}
