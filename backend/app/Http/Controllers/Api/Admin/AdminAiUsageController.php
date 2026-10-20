<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAiUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']]);
        $days = (int) ($validated['days'] ?? 30);
        $since = now()->subDays($days);
        $base = AiUsageLog::query()->where('ai_usage_logs.created_at', '>=', $since);

        $totals = (clone $base)->selectRaw('COUNT(*) as calls, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed, AVG(duration_ms) as avg_ms, SUM(estimated_tokens) as tokens')->first();

        $byOperation = (clone $base)
            ->select('operation', DB::raw('COUNT(*) as calls'), DB::raw('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed'), DB::raw('AVG(duration_ms) as avg_ms'), DB::raw('SUM(estimated_tokens) as tokens'))
            ->groupBy('operation')->orderByDesc('calls')->limit(25)->get()
            ->map(fn ($r) => [
                'operation' => $r->operation,
                'calls' => (int) $r->calls,
                'failed' => (int) $r->failed,
                'avg_ms' => (int) round((float) $r->avg_ms),
                'tokens' => (int) $r->tokens,
            ]);

        $byUser = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->select('ai_usage_logs.user_id', 'users.name', 'users.email', DB::raw('COUNT(*) as calls'), DB::raw('SUM(ai_usage_logs.estimated_tokens) as tokens'), DB::raw('MAX(ai_usage_logs.created_at) as last_used_at'))
            ->groupBy('ai_usage_logs.user_id', 'users.name', 'users.email')->orderByDesc('calls')->limit(25)->get()
            ->map(fn ($r) => [
                'user_id' => $r->user_id,
                'name' => $r->name ?? 'Background jobs',
                'email' => $r->email,
                'calls' => (int) $r->calls,
                'tokens' => (int) $r->tokens,
                'last_used_at' => $r->last_used_at ? \Illuminate\Support\Carbon::parse($r->last_used_at)->toIso8601String() : null,
            ]);

        $recent = AiUsageLog::query()->with('user:id,name')->latest('id')->limit(25)->get()
            ->map(fn (AiUsageLog $l) => [
                'id' => $l->id,
                'operation' => $l->operation,
                'user' => $l->user?->only(['id', 'name']),
                'status_code' => $l->status_code,
                'success' => $l->success,
                'duration_ms' => $l->duration_ms,
                'estimated_tokens' => $l->estimated_tokens,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'days' => $days,
                'totals' => [
                    'calls' => (int) ($totals->calls ?? 0),
                    'failed' => (int) ($totals->failed ?? 0),
                    'avg_ms' => (int) round((float) ($totals->avg_ms ?? 0)),
                    'tokens' => (int) ($totals->tokens ?? 0),
                ],
                'by_operation' => $byOperation,
                'by_user' => $byUser,
                'recent' => $recent,
            ],
        ]);
    }
}
