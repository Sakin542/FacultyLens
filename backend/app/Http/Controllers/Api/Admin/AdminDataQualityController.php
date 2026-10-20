<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminOperation;
use App\Models\DataQualityFinding;
use App\Services\Admin\DataQualityScanner;
use App\Services\Admin\OperationTracker;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Data-quality findings: read-only scans plus reviewer status tracking. No academic record is changed here. */
class AdminDataQualityController extends Controller
{
    public function __construct(protected DataQualityScanner $scanner, protected OperationTracker $operations, protected AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', ...DataQualityFinding::STATUSES])],
            'category' => ['nullable', 'string', 'max:24'],
            'severity' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'check' => ['nullable', Rule::in(array_keys(DataQualityScanner::CHECKS))],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = DataQualityFinding::query()->with('resolver:id,name')
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->latest('last_detected_at');
        $status = $validated['status'] ?? 'active';
        $status === 'active' ? $query->whereIn('status', ['open', 'acknowledged']) : $query->where('status', $status);
        foreach (['category', 'severity', 'check'] as $f) {
            if (!empty($validated[$f])) {
                $query->where($f, $validated[$f]);
            }
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        $active = DataQualityFinding::query()->whereIn('status', ['open', 'acknowledged']);
        $lastScan = AdminOperation::query()->where('type', 'data_quality_scan')->latest('id')->first();

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map->toPayload()->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'summary' => [
                    'by_status' => DataQualityFinding::query()->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status'),
                    'by_severity' => (clone $active)->select('severity', DB::raw('COUNT(*) as n'))->groupBy('severity')->pluck('n', 'severity'),
                    'by_check' => (clone $active)->select('check', DB::raw('COUNT(*) as n'))->groupBy('check')->pluck('n', 'check'),
                ],
                'checks' => collect(DataQualityScanner::CHECKS)->map(fn ($d, $k) => $d + ['key' => $k])->values(),
                'last_scan' => $lastScan?->load('initiator:id,name')->toPayload(),
            ],
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        if (AdminOperation::query()->where('type', 'data_quality_scan')->where('status', AdminOperation::RUNNING)->where('started_at', '>=', now()->subMinutes(10))->exists()) {
            return response()->json(['status' => 'error', 'message' => 'A scan is already running.'], 409);
        }
        $op = $this->operations->run('data_quality_scan', 'Data-quality scan', $request->user(), function () {
            $r = $this->scanner->scan();

            return ['total' => $r['findings'], 'success' => $r['checks'], 'failure' => 0, 'summary' => $r];
        });
        $this->audit->log('ADMIN_DATA_QUALITY_SCAN', 'AdminOperation', $op->id, ['status' => $op->status, 'findings' => $op->total_count], $request->user());

        return response()->json([
            'status' => $op->status === AdminOperation::COMPLETED ? 'success' : 'error',
            'message' => $op->status === AdminOperation::COMPLETED
                ? sprintf('%d finding(s): %d new, %d no longer detected.', $op->summary['findings'] ?? 0, $op->summary['new'] ?? 0, $op->summary['auto_resolved'] ?? 0)
                : ($op->error ?? 'The scan failed.'),
            'data' => $op->load('initiator:id,name')->toPayload(),
        ], $op->status === AdminOperation::COMPLETED ? 200 : 500);
    }

    public function update(Request $request, DataQualityFinding $finding): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(DataQualityFinding::STATUSES)],
            'note' => [Rule::requiredIf(fn () => in_array($request->input('status'), ['resolved', 'ignored'], true)), 'nullable', 'string', 'max:500'],
        ]);
        $closing = in_array($validated['status'], ['resolved', 'ignored'], true);
        $finding->forceFill([
            'status' => $validated['status'],
            'auto_resolved' => false,
            'resolved_by' => $closing ? $request->user()->id : null,
            'resolved_at' => $closing ? now() : null,
            'resolution_note' => $closing ? $validated['note'] : null,
        ])->save();
        $this->audit->log('ADMIN_DATA_QUALITY_UPDATED', $finding, $finding->id, ['status' => $finding->status, 'check' => $finding->check], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => $validated['status'] === 'resolved' ? 'Marked resolved. The next scan re-checks it and re-opens it if the problem is still there.' : 'Finding updated.',
            'data' => $finding->load('resolver:id,name')->toPayload(),
        ]);
    }
}
