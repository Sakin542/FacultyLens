<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentReport;
use App\Services\AssessmentReportService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssessmentReportController extends Controller
{
    protected AssessmentReportService $reportService;

    public function __construct(AssessmentReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Ensure the authenticated faculty user owns the assessment.
     */
    protected function authorizeAssessmentOwner(Request $request, Assessment $assessment): void
    {
        $assessment->loadMissing('course');
        if (!$request->user()->can('viewAnalysis', $assessment)) {
            abort(403, 'Unauthorized. You do not have access to this course or assessment.');
        }
    }

    /**
     * Retrieve structured academic report data for the assessment.
     */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeAssessmentOwner($request, $assessment);

        try {
            $data = $this->reportService->buildReportData($assessment);

            return response()->json([
                'status' => 'success',
                'data' => $data,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Generate or regenerate the authoritative assessment PDF report.
     */
    public function generate(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorizeAssessmentOwner($request, $assessment);

        try {
            $reportRecord = $this->reportService->generatePdf($assessment, $request->user());

            return response()->json([
                'status' => 'success',
                'message' => 'Academic assessment report PDF generated successfully.',
                'data' => [
                    'report' => [
                        'id' => $reportRecord->id,
                        'uuid' => $reportRecord->report_uuid,
                        'file_name' => $reportRecord->file_name,
                        'file_size' => $reportRecord->file_size,
                        'generation_status' => $reportRecord->generation_status,
                        'is_shareable' => $reportRecord->isShareActive(),
                        'share_token' => $reportRecord->share_token,
                        'generated_at' => $reportRecord->generated_at?->format('Y-m-d H:i:s'),
                    ],
                    'download_url' => url("/api/assessment-reports/{$reportRecord->id}/download"),
                ],
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate assessment report: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Stream or download the authoritative PDF report file.
     */
    public function download(Request $request, AssessmentReport $report): StreamedResponse|JsonResponse
    {
        $report->loadMissing('assessment.course');
        if (!$request->user()->can('viewAnalysis', $report->assessment)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not own this assessment report.',
            ], 403);
        }

        if (!Storage::disk('local')->exists($report->file_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Report file not found on disk. Please regenerate the report.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $report->file_path,
            $report->file_name,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $report->file_name . '"',
            ]
        );
    }

    /**
     * Create or retrieve a shareable link token for the assessment report.
     */
    public function share(Request $request, AssessmentReport $report): JsonResponse
    {
        $report->loadMissing('assessment.course');
        if (!$request->user()->can('update', $report->assessment)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not own this report.',
            ], 403);
        }

        $token = $this->reportService->shareReport($report);

        return response()->json([
            'status' => 'success',
            'message' => 'Report sharing link enabled successfully.',
            'data' => [
                'share_token' => $token,
                'share_url' => url("/shared/reports/{$token}"),
                'api_share_url' => url("/api/shared/reports/{$token}"),
                'is_shareable' => true,
            ],
        ], 200);
    }

    /**
     * Revoke shareable access for the assessment report.
     */
    public function revokeShare(Request $request, AssessmentReport $report): JsonResponse
    {
        $report->loadMissing('assessment.course');
        if (!$request->user()->can('update', $report->assessment)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. You do not own this report.',
            ], 403);
        }

        $this->reportService->revokeShare($report);

        return response()->json([
            'status' => 'success',
            'message' => 'Report sharing link revoked successfully.',
            'data' => [
                'is_shareable' => false,
            ],
        ], 200);
    }

    /**
     * Public read-only report viewer endpoint (accessed via unique share token).
     */
    public function viewShared(string $token): JsonResponse
    {
        $report = AssessmentReport::with(['assessment.course.user'])
            ->where('share_token', $token)
            ->where('is_shareable', true)
            ->where(function ($query) {
                $query->whereNull('revoked_at')
                    ->orWhere('revoked_at', '>', now());
            })
            ->first();

        if (!$report) {
            return response()->json([
                'status' => 'error',
                'message' => 'Report not found or the share link has expired/been revoked.',
            ], 404);
        }

        try {
            $data = $this->reportService->buildReportData($report->assessment);

            return response()->json([
                'status' => 'success',
                'data' => $data,
                'share_token' => $token,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Public download of shared PDF report.
     */
    public function downloadShared(string $token): StreamedResponse|JsonResponse
    {
        $report = AssessmentReport::where('share_token', $token)
            ->where('is_shareable', true)
            ->where(function ($query) {
                $query->whereNull('revoked_at')
                    ->orWhere('revoked_at', '>', now());
            })
            ->first();

        if (!$report) {
            return response()->json([
                'status' => 'error',
                'message' => 'Report not found or the share link has expired/been revoked.',
            ], 404);
        }

        if (!Storage::disk('local')->exists($report->file_path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Report file not found.',
            ], 404);
        }

        return Storage::disk('local')->download(
            $report->file_path,
            $report->file_name,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $report->file_name . '"',
            ]
        );
    }
}

