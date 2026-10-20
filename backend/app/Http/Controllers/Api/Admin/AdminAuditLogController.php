<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only audit log explorer. Audit rows are never edited or deleted through the API. */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'in:all,admin,security'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = AuditLog::query()->with('user:id,name,email')->latest('created_at')->latest('id');
        if (!empty($validated['action'])) {
            $query->where('action', strtoupper($validated['action']));
        }
        if ($term = trim((string) ($validated['search'] ?? ''))) {
            // Action names are UPPER_SNAKE; "role changed" finds ADMIN_ROLE_CHANGED (an `_` wildcard also matches the underscore)
            $query->where('action', 'like', '%'.strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $term)).'%');
        }
        if (!empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }
        if (!empty($validated['entity_id'])) {
            $query->where('entity_id', $validated['entity_id']);
        }
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        match ($validated['scope'] ?? 'all') {
            'admin' => $query->where('action', 'like', 'ADMIN%'),
            'security' => $query->where(fn ($q) => $q->whereIn('action', self::SECURITY_ACTIONS)->orWhere('action', 'like', 'AUTH%')),
            default => null,
        };
        if (!empty($validated['from'])) {
            $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($validated['from'])->startOfDay());
        }
        if (!empty($validated['to'])) {
            $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($validated['to'])->endOfDay());
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name] : null,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'course_id' => $l->course_id,
                'metadata' => $l->metadata,
                'ip_address' => $l->ip_address,
                'outcome' => $this->outcome($l->action),
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Distinct action and entity names for the filter dropdowns. */
    public function facets(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->limit(500)->pluck('action'),
                'entity_types' => AuditLog::query()->select('entity_type')->distinct()->orderBy('entity_type')->limit(200)->pluck('entity_type'),
            ],
        ]);
    }

    public const SECURITY_ACTIONS = [
        'ADMIN_LOGIN_REFUSED', 'ADMIN_ROLE_CHANGED', 'ADMIN_USER_SUSPENDED', 'ADMIN_USER_REINSTATED', 'ADMIN_SESSIONS_REVOKED',
        'ADMIN_TWO_FACTOR_RESET', 'ADMIN_PASSWORD_RESET_SENT', 'ADMIN_EXPORT_CREATED', 'ADMIN_EXPORT_DOWNLOADED', 'ADMIN_SETTINGS_UPDATED',
        'ADMIN_BACKUP_DOWNLOADED', 'PASSWORD_CHANGED', 'PASSWORD_RESET',
    ];

    protected function outcome(string $action): string
    {
        return match (true) {
            str_contains($action, 'FAILED'), str_contains($action, 'REFUSED'), str_contains($action, 'REJECTED') => 'failure',
            default => 'success',
        };
    }
}
