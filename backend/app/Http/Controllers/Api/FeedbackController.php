<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FeedbackAnalyticsService;
use App\Services\RecommendationFeedbackService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    protected RecommendationFeedbackService $feedbackService;
    protected FeedbackAnalyticsService $analyticsService;

    public function __construct(
        RecommendationFeedbackService $feedbackService,
        FeedbackAnalyticsService $analyticsService
    ) {
        $this->feedbackService = $feedbackService;
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get paginated feedback history for authenticated faculty member.
     * GET /api/feedback
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'course_id',
            'assessment_id',
            'decision',
            'rating',
            'reason',
            'search',
        ]);

        $perPage = (int) $request->input('per_page', 15);
        $paginated = $this->feedbackService->getFeedbackHistory($request->user(), $filters, $perPage);

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
        ], 200);
    }

    /**
     * Get aggregated feedback summary metrics and analytics.
     * GET /api/feedback/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $courseId = $request->input('course_id') ? (int) $request->input('course_id') : null;

        $summary = $this->feedbackService->getFeedbackSummary($request->user(), $courseId);
        $analytics = $this->analyticsService->getAnalytics($request->user(), $courseId);

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => array_merge($summary, [
                'analytics' => $analytics,
            ]),
        ], 200);
    }

    /**
     * Get paginated AI improvement signals originated from faculty feedback.
     * GET /api/ai/improvement-signals
     */
    public function improvementSignals(Request $request): JsonResponse
    {
        $filters = $request->only([
            'signal_type',
            'signal_value',
            'assessment_id',
        ]);

        $perPage = (int) $request->input('per_page', 15);
        $data = $this->feedbackService->getImprovementSignals($request->user(), $filters, $perPage);

        $paginator = $data['signals'];

        return response()->json([
            'status' => 'success',
            'success' => true,
            'data' => [
                'summary' => $data['summary'],
                'signals' => $paginator->items(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], 200);
    }
}

