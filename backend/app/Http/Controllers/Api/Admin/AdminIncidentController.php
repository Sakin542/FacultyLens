<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminIncident;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Incident lifecycle with an append-only timeline. Every change is written to the timeline and the audit log. */
class AdminIncidentController extends Controller
{
    public function __construct(protected AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', ...AdminIncident::STATUSES])],
            'category' => ['nullable', Rule::in(AdminIncident::CATEGORIES)],
            'severity' => ['nullable', Rule::in(AdminIncident::SEVERITIES)],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = AdminIncident::query()->with(['openedBy:id,name', 'assignee:id,name'])->withCount('alerts')->latest('opened_at');
        if (($validated['status'] ?? null) === 'active') {
            $query->whereIn('status', AdminIncident::ACTIVE_STATUSES);
        } elseif (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        foreach (['category', 'severity'] as $f) {
            if (!empty($validated[$f])) {
                $query->where($f, $validated[$f]);
            }
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 20));
        $resolved = AdminIncident::query()->whereNotNull('resolved_at')->where('opened_at', '>=', now()->subDays(90))->get(['opened_at', 'resolved_at']);

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (AdminIncident $i) => $i->toPayload() + ['alerts_count' => (int) $i->alerts_count])->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'counts' => AdminIncident::query()->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status'),
                'mean_minutes_to_resolve_90d' => $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($i) => $i->opened_at->diffInMinutes($i->resolved_at))),
                'options' => ['categories' => AdminIncident::CATEGORIES, 'severities' => AdminIncident::SEVERITIES, 'statuses' => AdminIncident::STATUSES],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(AdminIncident::CATEGORIES)],
            'severity' => ['required', Rule::in(AdminIncident::SEVERITIES)],
            'service' => ['nullable', 'string', 'max:32'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);
        $incident = DB::transaction(function () use ($validated, $request) {
            $incident = AdminIncident::query()->create($validated + [
                'status' => 'open', 'opened_by' => $request->user()->id, 'assigned_to' => $request->user()->id, 'opened_at' => now(),
            ]);
            $incident->events()->create(['user_id' => $request->user()->id, 'type' => 'created', 'body' => 'Incident opened.', 'created_at' => now()]);

            return $incident;
        });
        $this->audit->log('ADMIN_INCIDENT_OPENED', $incident, $incident->id, ['category' => $incident->category, 'severity' => $incident->severity], $request->user());

        return response()->json(['status' => 'success', 'message' => sprintf('INC-%04d opened.', $incident->id), 'data' => $this->detail($incident)], 201);
    }

    public function show(AdminIncident $incident): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->detail($incident)]);
    }

    public function update(Request $request, AdminIncident $incident): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(AdminIncident::STATUSES)],
            'severity' => ['sometimes', Rule::in(AdminIncident::SEVERITIES)],
            'category' => ['sometimes', Rule::in(AdminIncident::CATEGORIES)],
            'service' => ['sometimes', 'nullable', 'string', 'max:32'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'corrective_actions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        if (in_array($validated['status'] ?? null, ['resolved', 'closed'], true) && trim((string) ($validated['root_cause'] ?? $incident->root_cause)) === '') {
            return response()->json(['status' => 'error', 'message' => 'Record a root cause before resolving the incident.', 'errors' => ['root_cause' => ['A root cause is required to resolve.']]], 422);
        }

        DB::transaction(function () use ($validated, $incident, $request) {
            foreach (['status', 'severity', 'category'] as $field) {
                if (isset($validated[$field]) && $validated[$field] !== $incident->{$field}) {
                    $incident->events()->create(['user_id' => $request->user()->id, 'type' => $field === 'status' ? 'status' : 'severity', 'body' => ucfirst($field).' changed from '.$incident->{$field}.' to '.$validated[$field].'.', 'metadata' => ['field' => $field, 'from' => $incident->{$field}, 'to' => $validated[$field]], 'created_at' => now()]);
                }
            }
            foreach (['root_cause' => 'Root cause', 'corrective_actions' => 'Corrective actions'] as $field => $label) {
                if (array_key_exists($field, $validated) && $validated[$field] !== $incident->{$field}) {
                    $incident->events()->create(['user_id' => $request->user()->id, 'type' => 'note', 'body' => "{$label} updated.", 'created_at' => now()]);
                }
            }
            $incident->fill($validated);
            if (isset($validated['status'])) {
                $incident->resolved_at = in_array($validated['status'], ['resolved', 'closed'], true) ? ($incident->resolved_at ?? now()) : null;
            }
            $incident->save();
        });
        $this->audit->log('ADMIN_INCIDENT_UPDATED', $incident, $incident->id, ['fields' => array_keys($validated), 'status' => $incident->status], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Incident updated.', 'data' => $this->detail($incident->refresh())]);
    }

    public function addNote(Request $request, AdminIncident $incident): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $incident->events()->create(['user_id' => $request->user()->id, 'type' => 'note', 'body' => $validated['body'], 'created_at' => now()]);
        $incident->touch();

        return response()->json(['status' => 'success', 'message' => 'Note added.', 'data' => $this->detail($incident->refresh())], 201);
    }

    protected function detail(AdminIncident $incident): array
    {
        $incident->load(['openedBy:id,name', 'assignee:id,name', 'events.user:id,name', 'alerts']);
        $related = AuditLog::query()->with('user:id,name')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('entity_type', 'AdminIncident')->where('entity_id', $incident->id))
                ->orWhere(fn ($w) => $w->where('entity_type', 'AdminAlert')->whereIn('entity_id', $incident->alerts->pluck('id')->all() ?: [0])))
            ->latest('created_at')->limit(30)->get()
            ->map(fn (AuditLog $l) => ['id' => $l->id, 'action' => $l->action, 'user' => $l->user?->only(['id', 'name']), 'created_at' => $l->created_at?->toIso8601String()]);

        return $incident->toPayload(true) + ['audit' => $related];
    }
}
