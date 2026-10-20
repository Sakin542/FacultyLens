<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceWindow;
use App\Services\Admin\ServiceMonitor;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Planned maintenance windows: shown on the admin calendar and used to suppress alert notifications. */
class AdminMaintenanceController extends Controller
{
    public function __construct(protected AuditLogService $audit) {}

    public function index(): JsonResponse
    {
        $windows = MaintenanceWindow::query()->with('creator:id,name')
            ->where(fn ($q) => $q->where('ends_at', '>=', now()->subDays(60)))
            ->orderBy('starts_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $windows->map->toPayload()->values(),
            'meta' => ['services' => collect(ServiceMonitor::SERVICES)->map(fn ($d, $k) => ['key' => $k, 'label' => $d['label']])->values(), 'timezone' => config('app.timezone')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'services' => ['nullable', 'array'],
            'services.*' => [Rule::in(array_keys(ServiceMonitor::SERVICES))],
            'starts_at' => ['required', 'date', 'after:now -1 hour'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'suppress_alerts' => ['nullable', 'boolean'],
        ]);
        if (\Illuminate\Support\Carbon::parse($validated['starts_at'])->diffInHours(\Illuminate\Support\Carbon::parse($validated['ends_at'])) > 72) {
            return response()->json(['status' => 'error', 'message' => 'A maintenance window can last at most 72 hours.', 'errors' => ['ends_at' => ['At most 72 hours after the start.']]], 422);
        }
        $window = MaintenanceWindow::query()->create($validated + ['suppress_alerts' => $request->boolean('suppress_alerts', true), 'created_by' => $request->user()->id]);
        $this->audit->log('ADMIN_MAINTENANCE_SCHEDULED', $window, $window->id, ['starts_at' => $window->starts_at->toIso8601String(), 'ends_at' => $window->ends_at->toIso8601String(), 'services' => $window->services], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Maintenance window scheduled.', 'data' => $window->load('creator:id,name')->toPayload()], 201);
    }

    public function cancel(Request $request, MaintenanceWindow $window): JsonResponse
    {
        if ($window->cancelled_at || $window->ends_at->isPast()) {
            return response()->json(['status' => 'error', 'message' => 'Only scheduled or running windows can be cancelled.'], 422);
        }
        $window->forceFill(['cancelled_at' => now()])->save();
        $this->audit->log('ADMIN_MAINTENANCE_CANCELLED', $window, $window->id, null, $request->user());

        return response()->json(['status' => 'success', 'message' => 'Maintenance window cancelled.', 'data' => $window->load('creator:id,name')->toPayload()]);
    }
}
