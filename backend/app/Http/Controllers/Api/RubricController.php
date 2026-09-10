<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RubricUpdateRequest;
use App\Models\Question;
use App\Models\Rubric;
use App\Services\RubricException;
use App\Services\RubricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 25: AI Rubric Generator endpoints.
 * Every action verifies the User -> Course -> Assessment -> Question -> Rubric ownership chain.
 */
class RubricController extends Controller
{
    public function __construct(protected RubricService $rubricService) {}

    /**
     * POST /api/questions/{question}/rubrics/generate
     */
    public function generate(Request $request, Question $question): JsonResponse
    {
        if (!$this->canAccessQuestion($request, $question, 'edit_question')) {
            return $this->forbidden('Unauthorized access to this question.');
        }

        try {
            $rubric = $this->rubricService->generate($question, $request->user());
        } catch (RubricException $e) {
            return $this->rubricError($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Draft rubric generated. Please review and adjust before approving.',
            'data' => $this->present($rubric),
        ], 201);
    }

    /**
     * POST /api/rubrics/{rubric}/regenerate — creates a new draft version; the current one is preserved.
     */
    public function regenerate(Request $request, Rubric $rubric): JsonResponse
    {
        if (!$request->user()->can('view', $rubric)) {
            return $this->forbidden('Unauthorized access to this rubric.');
        }

        try {
            $newRubric = $this->rubricService->generate($rubric->question, $request->user());
        } catch (RubricException $e) {
            return $this->rubricError($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Draft rubric version {$newRubric->version} generated. Previous versions were kept.",
            'data' => $this->present($newRubric),
        ], 201);
    }

    /**
     * GET /api/questions/{question}/rubrics — all versions, newest first.
     */
    public function index(Request $request, Question $question): JsonResponse
    {
        if (!$this->canAccessQuestion($request, $question)) {
            return $this->forbidden('Unauthorized access to this question.');
        }

        $rubrics = $question->rubrics()->with('criteria')->get();

        return response()->json([
            'status' => 'success',
            'data' => $rubrics->map(fn (Rubric $r) => $this->present($r))->values(),
            'question' => [
                'id' => $question->id,
                'question_number' => $question->question_number,
                'marks' => (float) $question->marks,
            ],
        ]);
    }

    /**
     * GET /api/rubrics/{rubric}
     */
    public function show(Request $request, Rubric $rubric): JsonResponse
    {
        if (!$request->user()->can('view', $rubric)) {
            return $this->forbidden('Unauthorized access to this rubric.');
        }

        $rubric->load('criteria');

        return response()->json([
            'status' => 'success',
            'data' => $this->present($rubric),
        ]);
    }

    /**
     * PUT /api/rubrics/{rubric}
     */
    public function update(RubricUpdateRequest $request, Rubric $rubric): JsonResponse
    {
        if (!$request->user()->can('update', $rubric)) {
            return $this->forbidden('Unauthorized access to this rubric.');
        }

        try {
            $updated = $this->rubricService->update($rubric, $request->user(), $request->validated());
        } catch (RubricException $e) {
            return $this->rubricError($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Rubric draft saved.',
            'data' => $this->present($updated),
        ]);
    }

    /**
     * POST /api/rubrics/{rubric}/approve
     */
    public function approve(Request $request, Rubric $rubric): JsonResponse
    {
        // STEP 34: approval is an OWNER/EDITOR decision; reviewers may only view and comment.
        if (!$request->user()->can('view', $rubric)) {
            return $this->forbidden('Unauthorized access to this rubric.');
        }
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $rubric->question?->assessment?->course ?? $rubric->assessment?->course, 'approve_rubric')) {
            return $this->forbidden('You do not have permission to approve rubrics for this course.');
        }

        try {
            $approved = $this->rubricService->approve($rubric, $request->user());
        } catch (RubricException $e) {
            return $this->rubricError($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Rubric approved by faculty.',
            'data' => $this->present($approved),
        ]);
    }

    /**
     * DELETE /api/rubrics/{rubric}
     */
    public function destroy(Request $request, Rubric $rubric): JsonResponse
    {
        if (!$request->user()->can('delete', $rubric)) {
            return $this->forbidden('Unauthorized access to this rubric.');
        }

        $this->rubricService->delete($rubric, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Rubric deleted.',
        ]);
    }

    protected function canAccessQuestion(Request $request, Question $question, string $ability = 'view'): bool
    {
        $question->loadMissing('assessment.course');

        return app(\App\Services\CourseAccessService::class)->can($request->user(), $question->assessment?->course, $ability);
    }

    protected function present(Rubric $rubric): array
    {
        $rubric->loadMissing('criteria');

        return [
            'id' => $rubric->id,
            'question_id' => $rubric->question_id,
            'assessment_id' => $rubric->assessment_id,
            'created_by' => $rubric->created_by,
            'title' => $rubric->title,
            'total_marks' => (float) $rubric->total_marks,
            'criteria_total' => $rubric->criteria_total,
            'status' => $rubric->status,
            'version' => $rubric->version,
            'generation_method' => $rubric->generation_method,
            'is_ai_generated' => $rubric->is_ai_generated,
            'ai_model' => $rubric->ai_model,
            'ai_model_version' => $rubric->ai_model_version,
            'general_guidance' => $rubric->general_guidance,
            'generated_at' => $rubric->generated_at?->toISOString(),
            'approved_at' => $rubric->approved_at?->toISOString(),
            'approved_by' => $rubric->approved_by,
            'created_at' => $rubric->created_at?->toISOString(),
            'updated_at' => $rubric->updated_at?->toISOString(),
            'criteria' => $rubric->criteria->map(fn ($c) => [
                'id' => $c->id,
                'criterion' => $c->criterion,
                'description' => $c->description,
                'max_marks' => (float) $c->max_marks,
                'scoring_guidance' => $c->scoring_guidance,
                'expected_indicators' => $c->expected_indicators ?? [],
                'sort_order' => $c->sort_order,
            ])->values(),
        ];
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function rubricError(RubricException $e): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], $e->getStatus());
    }
}
