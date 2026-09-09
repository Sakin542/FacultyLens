<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnswerRubricAlignment;
use App\Models\StudentAnswer;
use App\Services\RubricAlignmentException;
use App\Services\RubricAlignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 28: Answer <-> Rubric Alignment.
 *
 * Alignment is decision support: it never changes marks, feedback, rubrics or answers.
 */
class RubricAlignmentController extends Controller
{
    public function __construct(protected RubricAlignmentService $service) {}

    /**
     * POST /api/student-answers/{answer}/rubric-alignment — queue an analysis (202 Accepted).
     */
    public function request(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('update', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        try {
            $outcome = $this->service->request($answer, $request->user());
        } catch (RubricAlignmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'processing',
            'message' => $outcome['created']
                ? 'Rubric alignment analysis has been queued. Alignment is an assistive signal, not a grade.'
                : 'Rubric alignment analysis is already in progress for this answer.',
            'data' => $this->service->present($outcome['alignment'], $answer),
        ], 202);
    }

    /**
     * GET /api/student-answers/{answer}/rubric-alignment — current analysis (with staleness).
     */
    public function show(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('view', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        $alignment = AnswerRubricAlignment::with('criterionAlignments')
            ->where('student_answer_id', $answer->id)
            ->current()
            ->first();

        if (!$alignment) {
            return response()->json([
                'status' => 'error',
                'message' => 'No rubric alignment analysis exists for this answer.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->service->present($alignment, $answer),
        ]);
    }

    /**
     * GET /api/student-answers/{answer}/rubric-alignment/history — all runs, newest first.
     */
    public function history(Request $request, StudentAnswer $answer): JsonResponse
    {
        if (!$request->user()->can('view', $answer)) {
            return $this->forbidden('Unauthorized access to this answer.');
        }

        $alignments = AnswerRubricAlignment::with('criterionAlignments')
            ->where('student_answer_id', $answer->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $alignments->map(fn ($a) => $this->service->present($a, $answer))->values(),
        ]);
    }

    /**
     * POST /api/rubric-alignments/{alignment}/regenerate — new run; previous runs are preserved.
     */
    public function regenerate(Request $request, AnswerRubricAlignment $alignment): JsonResponse
    {
        if (!$request->user()->can('update', $alignment)) {
            return $this->forbidden('Unauthorized access to this alignment analysis.');
        }

        try {
            $new = $this->service->regenerate($alignment, $request->user());
        } catch (RubricAlignmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'processing',
            'message' => 'A new rubric alignment analysis has been queued.',
            'data' => $this->service->present($new),
        ], 202);
    }

    /**
     * POST /api/rubric-alignments/{alignment}/review — faculty marks the analysis as reviewed.
     */
    public function review(Request $request, AnswerRubricAlignment $alignment): JsonResponse
    {
        if (!$request->user()->can('update', $alignment)) {
            return $this->forbidden('Unauthorized access to this alignment analysis.');
        }

        try {
            $updated = $this->service->markReviewed($alignment, $request->user());
        } catch (RubricAlignmentException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Alignment analysis marked as reviewed. Marks are unchanged.',
            'data' => $this->service->present($updated),
        ]);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function error(RubricAlignmentException $e): JsonResponse
    {
        $body = ['status' => 'error', 'message' => $e->getMessage()];
        if ($e->getDetails()) {
            $body['errors'] = $e->getDetails();
        }

        return response()->json($body, $e->getStatus());
    }
}
