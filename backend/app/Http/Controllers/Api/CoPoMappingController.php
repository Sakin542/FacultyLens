<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Services\AuditLogService;
use App\Services\CoPoMappingException;
use App\Services\CoPoMappingValidatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * STEP 31: CO/PO Mapping Validator — mappings, matrix, findings, CO coverage/performance, PO evidence,
 * and faculty confirmation of AI question->CO suggestions. Never changes mappings automatically.
 */
class CoPoMappingController extends Controller
{
    public function __construct(
        protected CoPoMappingValidatorService $service,
        protected AuditLogService $auditLogService,
    ) {}

    /** GET /api/courses/{course}/co-po-mapping */
    public function show(Request $request, Course $course): JsonResponse
    {
        if (!$this->owns($request, $course)) {
            return $this->forbidden();
        }
        $data = Cache::remember(CoPoMappingValidatorService::cacheKey($course->id, 'mapping'), (int) config('co_po.cache_ttl', 600), fn () => $this->service->overview($course));
        $this->auditLogService->log('CO_PO_MAPPING_VIEWED', $course, $course->id, [], $request->user());

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /** POST /api/courses/{course}/co-po-mapping/analyze */
    public function analyze(Request $request, Course $course): JsonResponse
    {
        if (!$this->owns($request, $course, 'run_analysis')) {
            return $this->forbidden();
        }
        try {
            $run = $this->service->analyze($course, $request->user(), $request->boolean('force'));
        } catch (CoPoMappingException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatus());
        }

        return response()->json([
            'status' => $run->status === 'FAILED' ? 'error' : 'success',
            'message' => $run->status === 'FAILED' ? $run->error_message : 'CO/PO mapping analysis generated. Findings are review signals, not an accreditation decision.',
            'data' => $this->service->present($run, true),
        ], $run->status === 'FAILED' ? 500 : 200);
    }

    /** GET /api/courses/{course}/co-po-mapping/matrix */
    public function matrix(Request $request, Course $course): JsonResponse
    {
        if (!$this->owns($request, $course)) {
            return $this->forbidden();
        }
        $data = Cache::remember(CoPoMappingValidatorService::cacheKey($course->id, 'matrix'), (int) config('co_po.cache_ttl', 600), fn () => $this->service->matrix($course));

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /** GET /api/courses/{course}/co-po-mapping/findings */
    public function findings(Request $request, Course $course): JsonResponse
    {
        return $this->runPart($request, $course, 'findings', fn ($run) => $this->service->presentFindings($run->load('findings')));
    }

    /** GET /api/courses/{course}/co-po-mapping/co-performance */
    public function coPerformance(Request $request, Course $course): JsonResponse
    {
        return $this->runPart($request, $course, 'performance', fn ($run) => $run->co_coverage ?? []);
    }

    /** GET /api/courses/{course}/co-po-mapping/po-evidence */
    public function poEvidence(Request $request, Course $course): JsonResponse
    {
        return $this->runPart($request, $course, 'evidence', fn ($run) => $run->po_evidence ?? []);
    }

    /** GET /api/courses/{course}/co-po-mapping/question-mappings */
    public function questionMappings(Request $request, Course $course): JsonResponse
    {
        if (!$this->owns($request, $course)) {
            return $this->forbidden();
        }
        $data = Cache::remember(CoPoMappingValidatorService::cacheKey($course->id, 'questions'), (int) config('co_po.cache_ttl', 600), fn () => $this->service->questionMappings($course));

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    // -------------------------------------------------------------- mappings

    /** POST /api/courses/{course}/co-po-mappings */
    public function storeMapping(Request $request, Course $course): JsonResponse
    {
        if (!$this->owns($request, $course, 'edit_course')) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'learning_outcome_id' => ['required', 'integer'],
            'program_outcome_id' => ['required', 'integer'],
            'mapping_level' => ['required', 'integer', Rule::in(CoPoMapping::LEVELS)],
            'justification' => ['nullable', 'string', 'max:1000'],
        ]);

        $error = $this->validateMappingTargets($course, (int) $data['learning_outcome_id'], (int) $data['program_outcome_id']);
        if ($error) {
            return response()->json(['status' => 'error', 'message' => $error], 422);
        }

        $mapping = CoPoMapping::updateOrCreate(
            ['learning_outcome_id' => $data['learning_outcome_id'], 'program_outcome_id' => $data['program_outcome_id']],
            ['course_id' => $course->id, 'mapping_level' => $data['mapping_level'], 'justification' => $data['justification'] ?? null, 'created_by' => $request->user()->id]
        );
        $this->afterMappingChange($course, $mapping, 'CO_PO_MAPPING_SAVED', $request);

        return response()->json(['status' => 'success', 'message' => 'Mapping saved.', 'data' => $this->service->presentMapping($mapping)], $mapping->wasRecentlyCreated ? 201 : 200);
    }

    /** PUT /api/co-po-mappings/{mapping} */
    public function updateMapping(Request $request, CoPoMapping $mapping): JsonResponse
    {
        $course = $mapping->course;
        if (!$course || !$this->owns($request, $course, 'edit_course')) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'mapping_level' => ['required', 'integer', Rule::in(CoPoMapping::LEVELS)],
            'justification' => ['nullable', 'string', 'max:1000'],
        ]);
        $mapping->update($data + ['created_by' => $request->user()->id]);
        $this->afterMappingChange($course, $mapping, 'CO_PO_MAPPING_UPDATED', $request);

        return response()->json(['status' => 'success', 'message' => 'Mapping updated.', 'data' => $this->service->presentMapping($mapping->fresh())]);
    }

    /** DELETE /api/co-po-mappings/{mapping} */
    public function destroyMapping(Request $request, CoPoMapping $mapping): JsonResponse
    {
        $course = $mapping->course;
        if (!$course || !$this->owns($request, $course, 'edit_course')) {
            return $this->forbidden();
        }
        $this->afterMappingChange($course, $mapping, 'CO_PO_MAPPING_DELETED', $request);
        $mapping->delete();

        return response()->json(['status' => 'success', 'message' => 'Mapping removed.']);
    }

    // ------------------------------------------------------ question <-> CO

    /** POST /api/questions/{question}/co-mappings/confirm  {learning_outcome_id} */
    public function confirmQuestionMapping(Request $request, Question $question): JsonResponse
    {
        return $this->decide($request, $question, true);
    }

    /** POST /api/questions/{question}/co-mappings/reject  {learning_outcome_id} */
    public function rejectQuestionMapping(Request $request, Question $question): JsonResponse
    {
        return $this->decide($request, $question, false);
    }

    protected function decide(Request $request, Question $question, bool $confirm): JsonResponse
    {
        $question->loadMissing('assessment.course');
        $course = $question->assessment?->course;
        if (!$course || !$this->owns($request, $course, 'edit_course')) {
            return $this->forbidden();
        }
        $data = $request->validate(['learning_outcome_id' => ['required', 'integer']]);
        $lo = LearningOutcome::find($data['learning_outcome_id']);
        if (!$lo || (int) $lo->course_id !== (int) $course->id) {
            return response()->json(['status' => 'error', 'message' => 'The course outcome does not belong to this course.'], 422);
        }
        try {
            $row = $this->service->decideQuestionMapping($question, $lo, $request->user(), $confirm);
        } catch (CoPoMappingException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatus());
        }

        return response()->json([
            'status' => 'success',
            'message' => $confirm ? 'Mapping confirmed by faculty.' : 'Suggestion rejected.',
            'data' => ['id' => $row->id, 'question_id' => $row->question_id, 'learning_outcome_id' => $row->learning_outcome_id, 'mapping_source' => $row->mapping_source, 'status' => $row->status, 'similarity_score' => $row->similarity_score !== null ? (float) $row->similarity_score : null, 'reviewed_at' => $row->reviewed_at?->toISOString()],
        ]);
    }

    // ---------------------------------------------------------------- helpers

    protected function runPart(Request $request, Course $course, string $part, callable $present): JsonResponse
    {
        if (!$this->owns($request, $course)) {
            return $this->forbidden();
        }
        $run = $this->service->currentRun($course);
        if (!$run || !$run->isCompleted()) {
            return response()->json(['status' => 'success', 'data' => [], 'run' => $run ? $this->service->present($run, false) : null]);
        }
        $data = Cache::remember(CoPoMappingValidatorService::cacheKey($course->id, $part) . ':' . $run->id, (int) config('co_po.cache_ttl', 600), fn () => $present($run));

        return response()->json(['status' => 'success', 'data' => $data, 'run' => $this->service->present($run, false)]);
    }

    protected function validateMappingTargets(Course $course, int $loId, int $poId): ?string
    {
        $lo = LearningOutcome::find($loId);
        if (!$lo || (int) $lo->course_id !== (int) $course->id) {
            return 'The course outcome does not belong to this course.';
        }
        if (!$course->program_id) {
            return 'Assign the course to a program before mapping its outcomes to program outcomes.';
        }
        $po = ProgramOutcome::find($poId);
        if (!$po || (int) $po->program_id !== (int) $course->program_id) {
            return 'The program outcome does not belong to this course\'s program.';
        }
        return null;
    }

    protected function afterMappingChange(Course $course, CoPoMapping $mapping, string $action, Request $request): void
    {
        CoPoMappingValidatorService::invalidateCache($course->id);
        $this->auditLogService->log($action, $mapping, $mapping->id, [
            'course_id' => $course->id,
            'learning_outcome_id' => $mapping->learning_outcome_id,
            'program_outcome_id' => $mapping->program_outcome_id,
            'mapping_level' => $mapping->mapping_level,
        ], $request->user());
    }

    /** STEP 34: reads need view_analysis; curriculum changes (mappings/confirmations) need edit_course. */
    protected function owns(Request $request, Course $course, string $ability = 'view_analysis'): bool
    {
        return app(\App\Services\CourseAccessService::class)->can($request->user(), $course, $ability);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access to this course.'], 403);
    }
}
