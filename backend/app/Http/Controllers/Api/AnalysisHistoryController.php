<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\AnalysisHistoryService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalysisHistoryController extends Controller
{
    protected AnalysisHistoryService $historyService;

    public function __construct(AnalysisHistoryService $historyService)
    {
        $this->historyService = $historyService;
    }

    /**
     * List completed analysis history with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'course_id',
            'assessment_type',
            'academic_year',
            'semester',
            'search',
            'sort',
        ]);

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $this->historyService->getHistory($request->user(), $filters, $perPage);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Get all historical versions for a specific assessment.
     */
    public function assessmentHistory(Request $request, Assessment $assessment): JsonResponse
    {
        try {
            $data = $this->historyService->getAssessmentHistory($assessment, $request->user());

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $data,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Get full details of a specific historical analysis snapshot.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->historyService->getAnalysisDetails($id, $request->user());

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $details,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 404;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Side-by-side comparison between two analyses.
     */
    public function compare(Request $request): JsonResponse
    {
        $leftId = (int) ($request->input('left') ?? $request->input('left_analysis_id'));
        $rightId = (int) ($request->input('right') ?? $request->input('right_analysis_id'));

        if (!$leftId || !$rightId) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Both left and right analysis IDs are required for comparison.',
            ], 422);
        }

        try {
            $comparison = $this->historyService->compareAnalyses($leftId, $rightId, $request->user());

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $comparison,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Get chronological quality trend data for a course.
     */
    public function trend(Request $request): JsonResponse
    {
        $courseId = $request->input('course_id');
        if (!$courseId) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'course_id is required to fetch quality trend data.',
            ], 422);
        }

        try {
            $trend = $this->historyService->getTrendData(
                $request->user(),
                (int) $courseId,
                $request->input('assessment_type')
            );

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $trend,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Get overall improvement summary for a course.
     */
    public function improvementSummary(Request $request): JsonResponse
    {
        $courseId = $request->input('course_id');
        if (!$courseId) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'course_id is required to fetch improvement summary.',
            ], 422);
        }

        try {
            $summary = $this->historyService->getImprovementSummary(
                $request->user(),
                (int) $courseId,
                $request->input('assessment_type')
            );

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $summary,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }
}

