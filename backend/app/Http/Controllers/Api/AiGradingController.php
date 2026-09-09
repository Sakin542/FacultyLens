<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinalGradeRequest;
use App\Models\AiGradingResult;
use App\Models\StudentAnswer;
use App\Services\AiGradingException;
use App\Services\AiGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 27: AI Grading Assistance.
 *
 * AI output is a suggestion. Faculty review it and record the final grade explicitly;
 * nothing here finalizes marks automatically.
 */
class AiGradingController extends Controller
{
    public function __construct(protected AiGradingService $service) {}

    /**
     * POST /api/student-answers/{answer}/ai-grade — queue an AI grading run (202 Accepted).
     */
    public function request(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('update', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        try {
            $outcome = $this->service->request($answer, $request->user());
        } catch (AiGradingException $e) {
            return $this->error($e);
        }

        $result = $outcome['result'];

        return response()->json([
            'status' => 'processing',
            'message' => $outcome['created']
                ? 'AI grading assistance has been queued. Faculty review is required before finalizing marks.'
                : 'AI grading assistance is already in progress for this answer.',
            'data' => $this->service->present($result, $answer),
        ], 202);
    }

    /**
     * GET /api/student-answers/{answer}/ai-grading — current AI grading result (with staleness).
     */
    public function show(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('view', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        $result = AiGradingResult::with('criterionResults')
            ->where('student_answer_id', $answer->id)
            ->current()
            ->first();

        if (!$result) {
            return response()->json([
                'status' => 'error',
                'message' => 'No AI grading result exists for this answer.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->service->present($result, $answer),
        ]);
    }

    /**
     * GET /api/student-answers/{answer}/ai-grading/history — all runs, newest first.
     */
    public function history(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('view', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        $results = AiGradingResult::with('criterionResults')
            ->where('student_answer_id', $answer->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $results->map(fn ($r) => $this->service->present($r, $answer))->values(),
        ]);
    }

    /**
     * POST /api/ai-grading/{result}/regenerate — new run; previous runs are preserved.
     */
    public function regenerate(Request $request, AiGradingResult $result): JsonResponse
    {
        if (!$request->user()->can('update', $result)) {
            return $this->forbidden('Unauthorized access to this AI grading result.');
        }

        try {
            $new = $this->service->regenerate($result, $request->user());
        } catch (AiGradingException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'processing',
            'message' => 'A new AI grading evaluation has been queued. Faculty review is required before finalizing marks.',
            'data' => $this->service->present($new),
        ], 202);
    }

    /**
     * POST /api/ai-grading/{result}/reject — faculty rejects the suggestion; marks are untouched.
     */
    public function reject(Request $request, AiGradingResult $result): JsonResponse
    {
        if (!$request->user()->can('update', $result)) {
            return $this->forbidden('Unauthorized access to this AI grading result.');
        }

        try {
            $updated = $this->service->rejectSuggestion($result, $request->user());
        } catch (AiGradingException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'AI suggestion rejected. Enter the final marks manually.',
            'data' => $this->service->present($updated),
        ]);
    }

    /**
     * POST /api/student-answers/{answer}/finalize-grade — faculty final marks (never automatic).
     */
    public function finalizeGrade(FinalGradeRequest $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('update', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        try {
            $updated = $this->service->finalizeGrade($answer, $request->user(), $request->validated());
        } catch (AiGradingException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Final marks saved.',
            'data' => StudentSubmissionController::presentAnswer($updated),
        ]);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function error(AiGradingException $e): JsonResponse
    {
        $body = ['status' => 'error', 'message' => $e->getMessage()];
        if ($e->getDetails()) {
            $body['errors'] = $e->getDetails();
        }

        return response()->json($body, $e->getStatus());
    }
}
