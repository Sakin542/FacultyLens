<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminOperation;
use App\Services\Admin\AdminExportService;
use App\Services\Admin\OperationTracker;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Administrative task center and CSV exports (stored privately, expiring, audited on creation and download). */
class AdminOperationController extends Controller
{
    public function __construct(protected OperationTracker $operations, protected AdminExportService $exports, protected AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in([AdminOperation::RUNNING, AdminOperation::COMPLETED, AdminOperation::FAILED])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $query = AdminOperation::query()->with('initiator:id,name')->latest('id');
        foreach (['type', 'status'] as $f) {
            if (!empty($validated[$f])) {
                $query->where($f, $validated[$f]);
            }
        }
        $page = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map->toPayload()->all(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'types' => AdminOperation::query()->select('type', DB::raw('COUNT(*) as n'))->groupBy('type')->pluck('n', 'type'),
                'datasets' => collect(AdminExportService::DATASETS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'ttl_hours' => (int) config('admin.exports.ttl_hours', 24),
            ],
        ]);
    }

    public function show(AdminOperation $operation): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $operation->load('initiator:id,name')->toPayload()]);
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dataset' => ['required', Rule::in(array_keys(AdminExportService::DATASETS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $label = AdminExportService::DATASETS[$validated['dataset']];
        $from = !empty($validated['from']) ? Carbon::parse($validated['from']) : null;
        $to = !empty($validated['to']) ? Carbon::parse($validated['to']) : null;

        $op = $this->operations->run('export', "Export: {$label}", $request->user(), function () use ($validated, $from, $to) {
            $file = $this->exports->build($validated['dataset'], $from, $to);

            return [
                'total' => $file['rows'], 'success' => $file['rows'], 'failure' => 0,
                'summary' => ['dataset' => $validated['dataset'], 'from' => $from?->toDateString(), 'to' => $to?->toDateString()],
                'file' => ['name' => $file['name'], 'contents' => $file['contents']],
            ];
        });
        $this->audit->log('ADMIN_EXPORT_CREATED', 'AdminOperation', $op->id, ['dataset' => $validated['dataset'], 'rows' => $op->total_count, 'status' => $op->status], $request->user());

        return response()->json([
            'status' => $op->status === AdminOperation::COMPLETED ? 'success' : 'error',
            'message' => $op->status === AdminOperation::COMPLETED ? "{$op->total_count} row(s) exported." : ($op->error ?? 'The export failed.'),
            'data' => $op->load('initiator:id,name')->toPayload(),
        ], $op->status === AdminOperation::COMPLETED ? 201 : 422);
    }

    public function download(Request $request, AdminOperation $operation): StreamedResponse|JsonResponse
    {
        if (!$operation->hasDownloadableResult() || !Storage::disk($operation->result_disk ?: 'local')->exists($operation->result_path)) {
            return response()->json(['status' => 'error', 'message' => 'This file has expired or is no longer available. Run the export again.'], 410);
        }
        $this->audit->log('ADMIN_EXPORT_DOWNLOADED', $operation, $operation->id, ['type' => $operation->type], $request->user());

        return Storage::disk($operation->result_disk ?: 'local')->download($operation->result_path, $operation->result_filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
