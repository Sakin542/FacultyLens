<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentBlueprintRequest;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Services\AssessmentBlueprintService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 37: Assessment Blueprint API. Authorization follows the STEP 34 course matrix
 * (view → members; edit/validate/finalize → OWNER/EDITOR via edit_assessment; generate → generate_questions).
 */
class AssessmentBlueprintController extends Controller
{
    public function __construct(protected AssessmentBlueprintService $service, protected CourseAccessService $access, protected AuditLogService $audit) {}

    /** GET /api/assessments/{assessment}/blueprint */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->allowed($request, $assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment.', 403);
        }
        $bp = $this->service->current($assessment);
        if (!$bp) {
            return $this->ok('No blueprint exists for this assessment yet.', ['blueprint' => null, 'versions' => [], 'validation' => null, 'coverage' => null, 'permissions' => $this->permissions($request, $assessment)]);
        }

        return $this->ok('Blueprint retrieved.', $this->payload($request, $bp));
    }

    /** POST /api/assessments/{assessment}/blueprint */
    public function store(StoreAssessmentBlueprintRequest $request, Assessment $assessment): JsonResponse
    {
        if (!$this->allowed($request, $assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to plan this assessment.', 403);
        }
        try {
            $bp = $this->service->create($request->user(), $assessment, $request->validated());
            $this->service->validate($request->user(), $bp, false);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok('Blueprint created.', $this->payload($request, $bp->fresh()), 201);
    }

    /** PUT /api/blueprints/{blueprint} */
    public function update(StoreAssessmentBlueprintRequest $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to edit this blueprint.', 403);
        }
        try {
            $wasFinalized = $blueprint->isFinalized();
            $bp = $this->service->update($request->user(), $blueprint, $request->validated());
            $this->service->validate($request->user(), $bp, false);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok($wasFinalized ? 'Finalized blueprint preserved; a new version was created.' : 'Blueprint updated.', $this->payload($request, $bp->fresh()));
    }

    /** DELETE /api/blueprints/{blueprint} */
    public function destroy(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to delete this blueprint.', 403);
        }
        try {
            $this->service->delete($request->user(), $blueprint);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok('Blueprint deleted.', null);
    }

    /** POST /api/blueprints/{blueprint}/validate */
    public function validateBlueprint(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to validate this blueprint.', 403);
        }
        $this->service->validate($request->user(), $blueprint);

        return $this->ok('Blueprint validated.', $this->payload($request, $blueprint->fresh()));
    }

    /** POST /api/blueprints/{blueprint}/finalize */
    public function finalize(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to finalize this blueprint.', 403);
        }
        try {
            $bp = $this->service->finalize($request->user(), $blueprint);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode(), ['validation' => $blueprint->fresh()->validation_result]);
        }

        return $this->ok('Blueprint finalized. It is now the planning source for question generation and selection.', $this->payload($request, $bp));
    }

    /** GET /api/blueprints/{blueprint}/coverage */
    public function coverage(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'view')) {
            return $this->error('Unauthorized access to this blueprint.', 403);
        }

        return $this->ok('Blueprint coverage retrieved.', $this->service->coverage($blueprint));
    }

    /** GET /api/blueprints/{blueprint}/comparison */
    public function comparison(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'view')) {
            return $this->error('Unauthorized access to this blueprint.', 403);
        }
        $cmp = $this->service->comparison($blueprint);
        $sync = null;
        if ($request->boolean('sync_recommendations') && $this->allowed($request, $blueprint->assessment, 'edit_assessment')) {
            $sync = $this->service->syncRecommendations($blueprint, $cmp);
        }
        $this->audit->log('BLUEPRINT_QUESTION_VALIDATED', $blueprint, $blueprint->id, ['assessment_id' => $blueprint->assessment_id, 'compliance_percent' => $cmp['compliance_percent'], 'summary' => $cmp['summary']], $request->user());

        return $this->ok('Blueprint comparison retrieved.', $cmp + ['recommendations_sync' => $sync]);
    }

    /** POST /api/blueprints/{blueprint}/generate-questions */
    public function generateQuestions(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'generate_questions')) {
            return $this->error('You are not allowed to generate questions for this course.', 403);
        }
        $opts = $request->validate(['language' => ['nullable', 'string', 'max:40'], 'document_scope' => ['nullable', 'array'], 'allow_draft' => ['nullable', 'boolean']]);
        try {
            $result = $this->service->generateQuestions($request->user(), $blueprint, $opts);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok('Question generation started from the blueprint.', $result, 202);
    }

    /** POST /api/blueprints/{blueprint}/validate-questions */
    public function validateQuestions(Request $request, AssessmentBlueprint $blueprint): JsonResponse
    {
        if (!$this->allowed($request, $blueprint->assessment, 'view')) {
            return $this->error('Unauthorized access to this blueprint.', 403);
        }
        $data = $request->validate(['question_ids' => ['nullable', 'array', 'max:200'], 'question_ids.*' => ['integer'], 'previous_question_ids' => ['nullable', 'array', 'max:200'], 'previous_question_ids.*' => ['integer']]);
        if (empty($data['question_ids']) && empty($data['previous_question_ids'])) {
            return $this->error('Provide question_ids and/or previous_question_ids to validate.', 422);
        }
        $result = $this->service->validateQuestions($blueprint, $data['question_ids'] ?? [], $data['previous_question_ids'] ?? []);
        $this->audit->log('BLUEPRINT_QUESTION_VALIDATED', $blueprint, $blueprint->id, ['assessment_id' => $blueprint->assessment_id, 'evaluated' => $result['evaluated'], 'matched' => $result['matched']], $request->user());

        return $this->ok('Questions validated against the blueprint.', $result);
    }

    // ------------------------------------------------------------- helpers

    protected function payload(Request $request, AssessmentBlueprint $bp): array
    {
        $bp->loadMissing('assessment');

        return [
            'blueprint' => $this->service->present($bp, false),
            'validation' => $bp->validation_result ? ['status' => $bp->validation_result['status'], 'errors' => $bp->validation_result['errors'], 'warnings' => $bp->validation_result['warnings'], 'recommendations' => $bp->validation_result['recommendations'],
                'completeness' => $bp->validation_result['completeness'], 'totals' => $bp->validation_result['totals'], 'time_indicator' => $bp->validation_result['time_indicator'], 'validated_at' => $bp->validation_result['validated_at']] : null,
            'coverage' => $bp->validation_result ? ['distributions' => $bp->validation_result['distributions'], 'matrices' => $bp->validation_result['matrices']] : null,
            'versions' => $this->service->versions($bp->assessment),
            'permissions' => $this->permissions($request, $bp->assessment),
        ];
    }

    protected function permissions(Request $request, Assessment $assessment): array
    {
        return ['edit' => $this->allowed($request, $assessment, 'edit_assessment'), 'generate' => $this->allowed($request, $assessment, 'generate_questions')];
    }

    protected function allowed(Request $request, Assessment $assessment, string $ability): bool
    {
        return $this->access->can($request->user(), $assessment->course, $ability);
    }

    protected function ok(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message] + $extra, $status);
    }
}
