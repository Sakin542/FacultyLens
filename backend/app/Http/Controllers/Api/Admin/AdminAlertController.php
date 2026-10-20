<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\AdminIncident;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminAlertController extends Controller
{
    public function __construct(protected AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', ...AdminAlert::STATUSES])],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = AdminAlert::query()->with(['acknowledgedBy:id,name', 'resolvedBy:id,name'])
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->latest('last_seen_at');
        $status = $validated['status'] ?? 'active';
        $status === 'active' ? $query->whereIn('status', ['open', 'acknowledged']) : $query->where('status', $status);
        if (!empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map->toPayload()->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'counts' => AdminAlert::query()->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status'),
                'rules' => config('admin.alerts'),
            ],
        ]);
    }

    public function acknowledge(Request $request, AdminAlert $alert): JsonResponse
    {
        if ($alert->status !== 'open') {
            return response()->json(['status' => 'error', 'message' => 'Only open alerts can be acknowledged.'], 422);
        }
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()])->save();
        $this->audit->log('ADMIN_ALERT_ACKNOWLEDGED', $alert, $alert->id, ['rule' => $alert->rule], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Alert acknowledged.', 'data' => $alert->load(['acknowledgedBy:id,name'])->toPayload()]);
    }

    public function resolve(Request $request, AdminAlert $alert): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);
        if ($alert->status === 'resolved') {
            return response()->json(['status' => 'error', 'message' => 'This alert is already resolved.'], 422);
        }
        $alert->forceFill(['status' => 'resolved', 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_note' => $validated['note']])->save();
        $this->audit->log('ADMIN_ALERT_RESOLVED', $alert, $alert->id, ['rule' => $alert->rule], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Alert resolved. It re-opens if the condition is measured again.', 'data' => $alert->load(['resolvedBy:id,name'])->toPayload()]);
    }

    /** Open an incident from an alert (or attach the alert to an existing incident). */
    public function escalate(Request $request, AdminAlert $alert): JsonResponse
    {
        $validated = $request->validate(['incident_id' => ['nullable', 'integer', 'exists:admin_incidents,id']]);

        $incident = DB::transaction(function () use ($validated, $alert, $request) {
            $incident = !empty($validated['incident_id'])
                ? AdminIncident::query()->findOrFail($validated['incident_id'])
                : AdminIncident::query()->create([
                    'title' => $alert->title,
                    'category' => match ($alert->service) {
                        'ai_service' => 'ai', 'mail' => 'email', 'auth' => 'security', 'backups', 'storage' => 'data', default => 'availability',
                    },
                    'severity' => $alert->severity === 'critical' ? 'high' : 'medium',
                    'status' => 'open',
                    'service' => $alert->service,
                    'summary' => $alert->message,
                    'opened_by' => $request->user()->id,
                    'opened_at' => now(),
                ]);
            if (empty($validated['incident_id'])) {
                $incident->events()->create(['user_id' => $request->user()->id, 'type' => 'created', 'body' => 'Opened from alert "'.$alert->title.'".', 'created_at' => now()]);
            }
            $incident->events()->create(['user_id' => $request->user()->id, 'type' => 'alert_linked', 'body' => $alert->title, 'metadata' => ['alert_id' => $alert->id], 'created_at' => now()]);
            $alert->forceFill(['incident_id' => $incident->id])->save();

            return $incident;
        });
        $this->audit->log('ADMIN_ALERT_ESCALATED', $alert, $alert->id, ['incident_id' => $incident->id], $request->user());

        return response()->json(['status' => 'success', 'message' => sprintf('Linked to INC-%04d.', $incident->id), 'data' => ['incident_id' => $incident->id]]);
    }
}
