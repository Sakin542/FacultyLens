<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use App\Services\RecommendationFeedbackService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationFeedbackController extends Controller
{
    protected RecommendationFeedbackService $feedbackService;

    public function __construct(RecommendationFeedbackService $feedbackService)
    {
        $this->feedbackService = $feedbackService;
    }

    /**
     * Submit faculty decision and optional feedback on a recommendation.
     * POST /api/recommendations/{recommendation}/feedback
     */
    public function submit(Request $request, Recommendation $recommendation): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:ACCEPTED,DISMISSED,REVIEWED,accepted,dismissed,reviewed'],
            'usefulness_rating' => ['nullable', 'integer', 'between:1,5'],
            'reason' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'faculty_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->feedbackService->submitFeedback($recommendation, $request->user(), $validated);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => 'Recommendation decision and feedback recorded successfully.',
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            $code = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Get feedback history for a specific recommendation.
     * GET /api/recommendations/{recommendation}/feedback
     */
    public function show(Request $request, Recommendation $recommendation): JsonResponse
    {
        try {
            $feedback = $this->feedbackService->getRecommendationFeedback($recommendation, $request->user());

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $feedback,
            ], 200);
        } catch (Exception $e) {
            $code = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 404;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Quick status update endpoint.
     * PATCH /api/recommendations/{recommendation}/status
     */
    public function updateStatus(Request $request, Recommendation $recommendation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:ACCEPTED,DISMISSED,REVIEWED,accepted,dismissed,reviewed'],
            'faculty_notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $this->feedbackService->updateRecommendationStatus(
                $recommendation,
                $request->user(),
                $validated['status'],
                $validated['faculty_notes'] ?? null,
                $validated['reason'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => "Recommendation status updated to {$validated['status']}.",
                'data' => $result['recommendation'],
            ], 200);
        } catch (Exception $e) {
            $code = str_contains($e->getMessage(), 'Unauthorized') ? 403 : 422;
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $code);
        }
    }
}

