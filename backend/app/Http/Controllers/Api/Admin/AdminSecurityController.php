<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Security operations view. "Facts" are audit rows exactly as recorded; "signals" are threshold-based observations
 * that need human review. Nothing here labels an account or address as malicious.
 */
class AdminSecurityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'in:1,7,30,90']]);
        $days = (int) $request->query('days', 7);
        $since = now()->subDays($days);
        $audit = fn () => AuditLog::query()->where('created_at', '>=', $since);

        $count = fn (string|array $actions) => $audit()->whereIn('action', (array) $actions)->count();

        $failedDaily = $audit()->where('action', 'AUTH_LOGIN_FAILED')->selectRaw('DATE(created_at) as d, COUNT(*) as n')->groupBy(DB::raw('DATE(created_at)'))->pluck('n', 'd');
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trend[] = ['date' => $date, 'total' => (int) ($failedDaily[$date] ?? 0)];
        }

        $targeted = $audit()->where('action', 'AUTH_LOGIN_FAILED')->whereNotNull('entity_id')
            ->select('entity_id', DB::raw('COUNT(*) as n'), DB::raw('COUNT(DISTINCT ip_address) as ips'), DB::raw('MAX(created_at) as last_at'))
            ->groupBy('entity_id')->orderByDesc('n')->limit(10)->get();
        $names = User::query()->whereIn('id', $targeted->pluck('entity_id'))->get(['id', 'name', 'is_active'])->keyBy('id');

        $sources = $audit()->where('action', 'AUTH_LOGIN_FAILED')->whereNotNull('ip_address')
            ->select('ip_address', DB::raw('COUNT(*) as n'), DB::raw('COUNT(DISTINCT entity_id) as accounts'))
            ->groupBy('ip_address')->orderByDesc('n')->limit(10)->get();

        $activeUsers = User::query()->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'));
        $activeCount = (clone $activeUsers)->count();
        $twoFactor = (clone $activeUsers)->whereNotNull('two_factor_confirmed_at')->count();
        $admin = $request->user();

        $signals = [];
        if (!$admin->hasTwoFactorEnabled()) {
            $signals[] = ['severity' => 'warning', 'title' => 'Administrator account has no two-factor authentication', 'detail' => 'Enable 2FA for the administrator from Settings → Security.'];
        }
        if (config('app.debug') && app()->environment('production')) {
            $signals[] = ['severity' => 'critical', 'title' => 'Debug mode is on in production', 'detail' => 'Set APP_DEBUG=false; debug pages can reveal configuration.'];
        }
        foreach ($targeted->where('n', '>=', (int) config('notifications.security.failed_login_threshold', 5)) as $t) {
            $signals[] = ['severity' => 'info', 'title' => 'Repeated failed sign-ins for '.($names[$t->entity_id]->name ?? 'an account'), 'detail' => "{$t->n} failures from {$t->ips} address(es). This is a volume signal; confirm with the account owner before acting."];
        }

        $sessions = null;
        if (config('session.driver') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))->whereNotNull('user_id')->where('last_activity', '>=', now()->subHours(2)->timestamp)->count();
        }

        $events = $audit()->with('user:id,name')
            ->where(fn ($q) => $q->whereIn('action', AdminAuditLogController::SECURITY_ACTIONS)->orWhere('action', 'like', 'AUTH%'))
            ->latest('created_at')->limit(30)->get()
            ->map(fn (AuditLog $l) => ['id' => $l->id, 'action' => $l->action, 'actor' => $l->user?->only(['id', 'name']), 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id, 'ip_address' => $l->ip_address, 'created_at' => $l->created_at?->toIso8601String()]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'days' => $days,
                'facts' => [
                    'failed_sign_ins' => $count('AUTH_LOGIN_FAILED'),
                    'refused_admin_sign_ins' => $count('ADMIN_LOGIN_REFUSED'),
                    'role_changes' => $count('ADMIN_ROLE_CHANGED'),
                    'suspensions' => $count('ADMIN_USER_SUSPENDED'),
                    'session_revocations' => $count('ADMIN_SESSIONS_REVOKED'),
                    'two_factor_resets' => $count('ADMIN_TWO_FACTOR_RESET'),
                    'sensitive_exports' => $count(['ADMIN_EXPORT_CREATED', 'ADMIN_EXPORT_DOWNLOADED', 'ADMIN_BACKUP_DOWNLOADED']),
                    'settings_changes' => $count('ADMIN_SETTINGS_UPDATED'),
                    'password_changes' => $count(['PASSWORD_CHANGED', 'PASSWORD_RESET']),
                ],
                'failed_trend' => $trend,
                'targeted_accounts' => $targeted->map(fn ($t) => ['user_id' => (int) $t->entity_id, 'name' => $names[$t->entity_id]->name ?? null, 'is_active' => isset($names[$t->entity_id]) ? $names[$t->entity_id]->isActive() : null, 'failures' => (int) $t->n, 'addresses' => (int) $t->ips, 'last_at' => \Illuminate\Support\Carbon::parse($t->last_at)->toIso8601String()]),
                'sources' => $sources->map(fn ($s) => ['ip_address' => $s->ip_address, 'failures' => (int) $s->n, 'accounts' => (int) $s->accounts]),
                'posture' => [
                    'active_accounts' => $activeCount,
                    'two_factor_accounts' => $twoFactor,
                    'two_factor_percent' => $activeCount ? round($twoFactor / $activeCount * 100, 1) : null,
                    'admin_two_factor' => $admin->hasTwoFactorEnabled(),
                    'active_sessions_2h' => $sessions,
                    'session_driver' => config('session.driver'),
                    'never_signed_in' => (clone $activeUsers)->whereNull('last_login_at')->count(),
                ],
                'signals' => $signals,
                'events' => $events,
                'dependency_scanning' => 'Not integrated: run `composer audit`, `npm audit` and `pip-audit` in CI and record findings in an incident if action is needed.',
            ],
        ]);
    }
}
