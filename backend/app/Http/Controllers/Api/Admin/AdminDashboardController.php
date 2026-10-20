<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\AdminIncident;
use App\Models\AdminOperation;
use App\Models\AiUsageLog;
use App\Models\AuditLog;
use App\Models\DataQualityFinding;
use App\Models\EmailDelivery;
use App\Models\HealthCheckResult;
use App\Models\User;
use App\Services\Admin\DatabaseBackupService;
use App\Services\Admin\ServiceMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard: every number comes from a table query; nothing is estimated or invented. Metrics with a time
 * dimension are reported for the selected period and the equal-length period before it.
 */
class AdminDashboardController extends Controller
{
    public const RANGES = [7, 30, 90];

    public function __construct(protected ServiceMonitor $monitor, protected DatabaseBackupService $backups) {}

    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'in:'.implode(',', self::RANGES)],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $key = "admin:dashboard:{$days}";
        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }
        $data = Cache::remember($key, 60, fn () => $this->build($days, $request->user()));

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    protected function build(int $days, User $admin): array
    {
        $end = now();
        $start = now()->subDays($days)->startOfDay();
        $prevStart = $start->copy()->subDays($days);

        $period = fn ($query, string $column) => [
            'current' => (clone $query)->whereBetween($column, [$start, $end])->count(),
            'previous' => (clone $query)->whereBetween($column, [$prevStart, $start])->count(),
        ];

        $activeUsers = User::query()->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'));
        $docs = DB::table('document_processings')->select('processing_status', DB::raw('COUNT(*) as n'))->groupBy('processing_status')->pluck('n', 'processing_status');
        $aiChecks = HealthCheckResult::query()->where('service', 'ai_service')->whereBetween('checked_at', [$start, $end]);
        $aiCheckCount = (clone $aiChecks)->count();
        $latestSnapshotAt = HealthCheckResult::query()->max('checked_at');
        $latestSnapshot = $latestSnapshotAt ? HealthCheckResult::query()->where('checked_at', $latestSnapshotAt)->get()->keyBy('service') : collect();
        $pendingJobs = $latestSnapshot->get('queue')?->detail['pending'] ?? null;
        $lastBackup = $this->backups->latestCompleted();
        $lastScan = AdminOperation::query()->where('type', 'data_quality_scan')->where('status', AdminOperation::COMPLETED)->latest('completed_at')->first();

        $kpis = [
            'users_total' => ['value' => User::query()->count()],
            'active_faculty' => ['value' => (clone $activeUsers)->whereIn(DB::raw('UPPER(COALESCE(role, \'FACULTY\'))'), ['FACULTY', 'DEPARTMENT_HEAD'])->count()],
            'suspended_accounts' => ['value' => User::query()->where('is_active', false)->count()],
            'new_users' => $period(User::query(), 'created_at'),
            'unverified_accounts' => ['value' => User::query()->whereNull('email_verified_at')->count()],
            'departments' => ['value' => $this->departmentCount()],
            'courses' => ['value' => DB::table('courses')->count()] + ['new' => $period(DB::table('courses'), 'created_at')],
            'assessments' => ['value' => DB::table('assessments')->count()] + ['new' => $period(DB::table('assessments'), 'created_at')],
            'analyses_completed' => $period(DB::table('analysis_reports')->where('analysis_status', 'completed'), 'created_at'),
            'analyses_failed' => $period(DB::table('analysis_reports')->where('analysis_status', 'failed'), 'created_at'),
            'ai_calls' => $period(AiUsageLog::query(), 'created_at'),
            'ai_availability' => [
                'value' => $aiCheckCount ? round((clone $aiChecks)->where('status', ServiceMonitor::HEALTHY)->count() / $aiCheckCount * 100, 1) : null,
                'checks' => $aiCheckCount,
            ],
            'documents' => ['by_status' => ['uploaded' => (int) ($docs['uploaded'] ?? 0), 'processing' => (int) ($docs['processing'] ?? 0), 'completed' => (int) ($docs['completed'] ?? 0), 'failed' => (int) ($docs['failed'] ?? 0)]],
            'pending_jobs' => ['value' => $pendingJobs, 'as_of' => $latestSnapshotAt ? Carbon::parse($latestSnapshotAt)->toIso8601String() : null],
            'failed_jobs' => $period(DB::table('failed_jobs'), 'failed_at'),
            'email_failures' => $period(EmailDelivery::query()->where('status', EmailDelivery::FAILED), 'failed_at'),
            'unread_alerts' => ['value' => $admin->notifications()->whereNull('read_at')->whereNull('dismissed_at')->count()],
            'open_alerts' => ['value' => AdminAlert::query()->whereIn('status', ['open', 'acknowledged'])->count()],
            'open_incidents' => ['value' => AdminIncident::query()->whereIn('status', AdminIncident::ACTIVE_STATUSES)->count()],
            'data_quality_open' => ['value' => DataQualityFinding::query()->whereIn('status', ['open', 'acknowledged'])->count(), 'last_scan_at' => $lastScan?->completed_at?->toIso8601String()],
            'last_backup' => ['value' => $lastBackup?->completed_at?->toIso8601String(), 'verification_status' => $lastBackup?->verification_status],
            'integrations_healthy' => [
                'value' => $latestSnapshot->only(['ai_service', 'mail', 'broadcasting', 'cache', 'database'])->where('status', ServiceMonitor::HEALTHY)->count(),
                'of' => $latestSnapshot->only(['ai_service', 'mail', 'broadcasting', 'cache', 'database'])->count(),
            ],
        ];

        return [
            'range' => ['days' => $days, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'previous_start' => $prevStart->toIso8601String()],
            'kpis' => $kpis,
            'series' => [
                'new_users' => $this->daily(DB::table('users'), 'created_at', $start, $days),
                'ai_calls' => $this->daily(DB::table('ai_usage_logs'), 'created_at', $start, $days, 'success'),
                'analyses' => $this->daily(DB::table('analysis_reports')->whereIn('analysis_status', ['completed', 'failed']), 'created_at', $start, $days, null, "analysis_status = 'completed'"),
                'emails' => $this->daily(DB::table('email_deliveries')->whereIn('status', [EmailDelivery::SENT, EmailDelivery::FAILED]), 'created_at', $start, $days, null, "status = 'SENT'"),
                'failed_jobs' => $this->daily(DB::table('failed_jobs'), 'failed_at', $start, $days),
                'admin_activity' => $this->daily(DB::table('audit_logs')->where('action', 'like', 'ADMIN%')->where('action', '!=', 'ADMIN_LOGIN_REFUSED'), 'created_at', $start, $days),
            ],
            'account_status' => $this->accountStatus(),
            'services' => $latestSnapshot->map(fn (HealthCheckResult $r) => ['service' => $r->service, 'label' => ServiceMonitor::SERVICES[$r->service]['label'] ?? $r->service, 'status' => $r->status, 'response_ms' => $r->response_ms])->values(),
            'critical_alerts' => AdminAlert::query()->whereIn('status', ['open', 'acknowledged'])->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->latest('last_seen_at')->limit(5)->get()->map->toPayload(),
            'recent_activity' => AuditLog::query()->with('user:id,name')->where('action', 'like', 'ADMIN%')->where('action', '!=', 'ADMIN_LOGIN_REFUSED')->latest('created_at')->limit(8)->get()
                ->map(fn (AuditLog $l) => ['id' => $l->id, 'action' => $l->action, 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id, 'user' => $l->user?->only(['id', 'name']), 'created_at' => $l->created_at?->toIso8601String()]),
            'freshness' => [
                'generated_at' => now()->toIso8601String(),
                'health_snapshot_at' => $latestSnapshotAt ? Carbon::parse($latestSnapshotAt)->toIso8601String() : null,
                'data_quality_scan_at' => $lastScan?->completed_at?->toIso8601String(),
                'scheduler_heartbeat_at' => $this->monitor->lastHeartbeat(),
            ],
        ];
    }

    /**
     * Zero-filled daily counts, oldest first. With $okColumn/$okExpression each day also carries `ok` and `failed`.
     *
     * @return list<array{date: string, total: int, ok?: int, failed?: int}>
     */
    protected function daily($query, string $column, Carbon $start, int $days, ?string $okColumn = null, ?string $okExpression = null): array
    {
        $select = ["DATE({$column}) as d", 'COUNT(*) as total'];
        if ($okColumn) {
            $select[] = "SUM(CASE WHEN {$okColumn} = 1 THEN 1 ELSE 0 END) as ok";
        } elseif ($okExpression) {
            $select[] = "SUM(CASE WHEN {$okExpression} THEN 1 ELSE 0 END) as ok";
        }
        $rows = (clone $query)->where($column, '>=', $start)->selectRaw(implode(', ', $select))->groupBy(DB::raw("DATE({$column})"))->get()->keyBy('d');
        $split = $okColumn || $okExpression;

        $out = [];
        for ($i = 0; $i <= $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            if ($date > now()->toDateString()) {
                break;
            }
            $r = $rows->get($date);
            $point = ['date' => $date, 'total' => (int) ($r->total ?? 0)];
            if ($split) {
                $point['ok'] = (int) ($r->ok ?? 0);
                $point['failed'] = $point['total'] - $point['ok'];
            }
            $out[] = $point;
        }

        return $out;
    }

    protected function accountStatus(): array
    {
        return User::query()
            ->selectRaw("UPPER(COALESCE(role, 'FACULTY')) as role, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as deactivated, COUNT(*) as total")
            ->groupBy(DB::raw("UPPER(COALESCE(role, 'FACULTY'))"))
            ->get()
            ->map(fn ($r) => ['role' => $r->role, 'active' => (int) $r->total - (int) $r->deactivated, 'deactivated' => (int) $r->deactivated])
            ->values()->all();
    }

    protected function departmentCount(): int
    {
        $fromUsers = DB::table('users')->whereNotNull('department')->where('department', '!=', '')->pluck('department');
        $fromPrograms = DB::table('programs')->whereNotNull('department')->where('department', '!=', '')->pluck('department');

        return $fromUsers->merge($fromPrograms)->map(fn ($d) => mb_strtolower(trim((string) $d)))->unique()->count();
    }
}
