<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\PermissionMatrix;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Roles, the enforced permission matrix, the privileged-access inventory and role-change history. */
class AdminAccessController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = User::query()
            ->selectRaw("UPPER(COALESCE(role, 'FACULTY')) as role, COUNT(*) as n")
            ->groupBy(DB::raw("UPPER(COALESCE(role, 'FACULTY'))"))
            ->pluck('n', 'role');

        $roles = collect(PermissionMatrix::ROLES)->map(fn ($def, $key) => $def + ['key' => $key, 'members' => (int) ($counts[$key] ?? 0)])->values();

        // Privileged inventory: the administrator plus department heads (cross-course read access)
        $privileged = User::query()->whereIn(DB::raw("UPPER(COALESCE(role, 'FACULTY'))"), ['ADMIN', 'DEPARTMENT_HEAD'])->orderBy('role')->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => strtoupper($u->role ?? 'FACULTY'),
                'department' => $u->department,
                'is_active' => $u->isActive(),
                'effective' => $u->isAdmin() || $u->isDepartmentHead(),
                'two_factor_enabled' => $u->hasTwoFactorEnabled(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'note' => strtoupper((string) $u->role) === 'ADMIN' && !$u->isAdmin() ? 'ADMIN role without the configured ADMIN_EMAIL grants nothing; run php artisan admin:ensure.' : null,
            ]);

        $history = AuditLog::query()->with('user:id,name')
            ->whereIn('action', ['ADMIN_ROLE_CHANGED', 'ADMIN_USER_BULK_UPDATED', 'ADMIN_USER_CREATED'])
            ->latest('created_at')->limit(100)->get()
            ->filter(fn (AuditLog $l) => $l->action === 'ADMIN_ROLE_CHANGED' || $l->action === 'ADMIN_USER_CREATED' || isset($l->metadata['changes']['role']))
            ->take(50);
        $names = User::query()->whereIn('id', $history->pluck('entity_id')->filter()->unique())->pluck('name', 'id');
        $history = $history
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->user?->only(['id', 'name']),
                'target_id' => $l->entity_id,
                'target_name' => $names[$l->entity_id] ?? null,
                'from' => $l->metadata['from'] ?? ($l->metadata['changes']['role']['from'] ?? null),
                'to' => $l->metadata['to'] ?? ($l->metadata['changes']['role']['to'] ?? ($l->metadata['role'] ?? null)),
                'reason' => $l->metadata['reason'] ?? null,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'roles' => $roles,
                'modules' => PermissionMatrix::modules(),
                'privileged' => $privileged,
                'history' => $history,
                'admin_lock' => [
                    'email_configured' => (string) config('admin.email') !== '',
                    'description' => 'Only the account matching ADMIN_EMAIL with the ADMIN role can open the admin panel. The ADMIN role cannot be granted from the panel.',
                ],
            ],
        ]);
    }
}
