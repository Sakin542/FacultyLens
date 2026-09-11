<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReportRequest;
use App\Http\Requests\PreviewReportRequest;
use App\Models\InstitutionalReport;
use App\Services\AuditLogService;
use App\Services\InstitutionalReportService;
use App\Services\ReportAuthorizationService;
use App\Services\ReportExportService;
use App\Services\Reports\ReportValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STEP 39: Institutional Export & Reporting API.
 * Preview → validate access → generate → export → download → audit. Files are private and streamed
 * only after a policy check; scope/role claims from the client are never trusted.
 */
class InstitutionalReportController extends Controller
{
    public function __construct(
        protected InstitutionalReportService $service,
        protected ReportAuthorizationService $authorization,
        protected ReportExportService $export,
        protected AuditLogService $audit,
    ) {}

    /** GET /api/reports/types — report types the user may run, their scopes, formats and filter options. */
    public function types(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['status' => 'success', 'data' => [
            'types' => $this->authorization->availableTypes($user),
            'scopes' => $this->authorization->allowedScopes($user),
            'formats' => (array) config('institutional_reports.formats'),
            'scope_filters' => (array) config('institutional_reports.scope_filters'),
            'expiration_days' => (int) config('institutional_reports.expiration_days'),
            'async_threshold_records' => (int) config('institutional_reports.async_threshold_records'),
        ]]);
    }

    /** GET /api/reports/filters?report_type=&scope_type= */
    public function filters(Request $request): JsonResponse
    {
        $data = $request->validate(['report_type' => ['nullable', 'string', 'max:40'], 'scope_type' => ['nullable', 'string', 'max:30']]);

        return response()->json(['status' => 'success', 'data' => $this->authorization->filterOptions($request->user(), $data['report_type'] ?? null, $data['scope_type'] ?? null)]);
    }

    /** GET /api/reports — the caller's own reports ("My Reports"). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'report_type' => ['nullable', 'string', 'max:40'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = InstitutionalReport::query()->where('created_by', $request->user()->id)->orderByDesc('id');
        if (! empty($data['status'])) {
            $q->where('status', strtoupper($data['status']));
        }
        if (! empty($data['report_type'])) {
            $q->where('report_type', strtoupper($data['report_type']));
        }
        $page = $q->paginate((int) ($data['per_page'] ?? 20));

        return response()->json(['status' => 'success', 'data' => [
            'items' => array_map(fn ($r) => $this->service->present($r), $page->items()),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]]);
    }

    /** POST /api/reports/preview */
    public function preview(PreviewReportRequest $request): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $this->service->preview($request->user(), $request->validated())]);
        } catch (AuthorizationException $e) {
            return $this->forbidden();
        } catch (ReportValidationException $e) {
            return $this->invalid($e);
        }
    }

    /** POST /api/reports */
    public function store(CreateReportRequest $request): JsonResponse
    {
        try {
            $report = $this->service->create($request->user(), $request->validated());
        } catch (AuthorizationException $e) {
            return $this->forbidden();
        } catch (ReportValidationException $e) {
            return $this->invalid($e);
        }

        return response()->json(['status' => 'success', 'message' => $report->is_async ? 'Report queued for generation.' : ($report->status === InstitutionalReport::STATUS_COMPLETED ? 'Report generated.' : 'Report generation failed. Please try again.'),
            'data' => $this->service->present($report)], $report->is_async ? 202 : 201);
    }

    /** GET /api/reports/{report} */
    public function show(Request $request, InstitutionalReport $report): JsonResponse
    {
        if (! $request->user()->can('view', $report)) {
            return $this->forbidden();
        }

        return response()->json(['status' => 'success', 'data' => $this->service->present($report)]);
    }

    /** GET /api/reports/{report}/download — authenticated, policy-checked stream from the private disk. */
    public function download(Request $request, InstitutionalReport $report): StreamedResponse|JsonResponse
    {
        if (! $request->user()->can('download', $report)) {
            return $this->forbidden();
        }
        if ($report->status !== InstitutionalReport::STATUS_COMPLETED) {
            return response()->json(['status' => 'error', 'message' => 'The report is not ready for download.'], 409);
        }
        if ($report->isExpired() || $report->file_deleted_at) {
            return response()->json(['status' => 'error', 'message' => 'This report has expired. Generate it again to download a fresh copy.'], 410);
        }
        $disk = Storage::disk($this->export->disk());
        if (! $report->file_path || ! $disk->exists($report->file_path)) {
            return response()->json(['status' => 'error', 'message' => 'The report file is unavailable. Generate it again.'], 410);
        }
        $this->audit->log('REPORT_DOWNLOADED', $report, $report->id, ['report_type' => $report->report_type, 'scope' => $report->scope_type, 'format' => $report->format, 'contains_student_data' => $report->contains_student_data], $request->user());

        return $disk->download($report->file_path, $report->file_name, ['Content-Type' => $this->export->contentType($report->format), 'X-Content-Type-Options' => 'nosniff']);
    }

    /** DELETE /api/reports/{report} */
    public function destroy(Request $request, InstitutionalReport $report): JsonResponse
    {
        if (! $request->user()->can('delete', $report)) {
            return response()->json(['status' => 'error', 'message' => $report->status === InstitutionalReport::STATUS_PROCESSING ? 'A report that is being generated cannot be deleted.' : 'You are not authorized to delete this report.'], 403);
        }
        $this->service->delete($request->user(), $report);

        return response()->json(['status' => 'success', 'message' => 'Report deleted.']);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'You are not authorized to generate this report.'], 403);
    }

    protected function invalid(ReportValidationException $e): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'errors' => $e->errors ?: null], $e->status);
    }
}
