<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuestionGenerationRequestForm;
use App\Models\GeneratedQuestion;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Services\QuestionGenerationService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 33: Constrained Question Generator API. Drafts only — nothing is published without approval + an
 * explicit "add to assessment" action.
 */
class QuestionGenerationController extends Controller
{
    public function __construct(protected QuestionGenerationService $service) {}

    /** GET /api/question-generation?course_id=&assessment_id= */
    public function index(Request $request): JsonResponse
    {
        // STEP 34: requests on any course the user owns or collaborates on.
        $query = QuestionGenerationRequest::whereIn('course_id', Course::query()->accessibleBy($request->user())->select('courses.id'))
            ->with(['course:id,course_code,course_name', 'assessment:id,title', 'learningOutcome:id,code,description', 'programOutcome:id,code,title'])
            ->withCount(['generatedQuestions', 'generatedQuestions as approved_count' => fn ($q) => $q->where('review_status', GeneratedQuestion::REVIEW_APPROVED)])
            ->orderByDesc('id');
        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->input('course_id'));
        }
        if ($request->filled('assessment_id')) {
            $query->where('assessment_id', (int) $request->input('assessment_id'));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Generation requests retrieved.',
            'data' => $query->limit(100)->get()->map(fn ($r) => $this->requestPayload($r))->values(),
        ]);
    }

    /** POST /api/question-generation */
    public function store(QuestionGenerationRequestForm $form): JsonResponse
    {
        try {
            $generation = $this->service->createRequest($form->user(), $form->validated());
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        $generation->load(['course:id,course_code,course_name', 'assessment:id,title', 'learningOutcome:id,code,description', 'programOutcome:id,code,title', 'generatedQuestions']);

        return response()->json([
            'status' => 'success',
            'message' => $generation->generation_status === QuestionGenerationRequest::STATUS_COMPLETED
                ? 'Questions generated. Review the drafts before use.'
                : 'Generation request queued. Drafts will appear when processing completes.',
            'data' => $this->requestPayload($generation, true),
        ], 202);
    }

    /** GET /api/question-generation/{generation} */
    public function show(Request $request, QuestionGenerationRequest $generation): JsonResponse
    {
        if (!$this->access()->can($request->user(), $generation->course, 'view')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to generation request.'], 403);
        }
        $generation->load(['course:id,course_code,course_name', 'assessment:id,title,total_marks', 'learningOutcome:id,code,description', 'programOutcome:id,code,title', 'generatedQuestions']);

        return response()->json(['status' => 'success', 'message' => 'Generation request retrieved.', 'data' => $this->requestPayload($generation, true)]);
    }

    /** GET /api/question-generation/{generation}/questions */
    public function questions(Request $request, QuestionGenerationRequest $generation): JsonResponse
    {
        if (!$this->access()->can($request->user(), $generation->course, 'view')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to generation request.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Generated questions retrieved.',
            'data' => $generation->generatedQuestions()->get()->map(fn ($q) => $this->questionPayload($q))->values(),
        ]);
    }

    /** POST /api/question-generation/{generation}/regenerate */
    public function regenerate(Request $request, QuestionGenerationRequest $generation): JsonResponse
    {
        if (!$this->access()->can($request->user(), $generation->course, 'generate_questions')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to generation request.'], 403);
        }
        $validated = $request->validate($this->feedbackRules());

        try {
            $generation = $this->service->regenerateRequest($generation, $request->user(), $this->feedbackList($validated));
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }
        $generation->load(['course:id,course_code,course_name', 'assessment:id,title', 'learningOutcome:id,code,description', 'programOutcome:id,code,title', 'generatedQuestions']);

        return response()->json(['status' => 'success', 'message' => 'Regeneration queued.', 'data' => $this->requestPayload($generation, true)], 202);
    }

    /** PUT /api/generated-questions/{generatedQuestion} */
    public function update(Request $request, GeneratedQuestion $generatedQuestion): JsonResponse
    {
        if ($denied = $this->deny($request, $generatedQuestion)) {
            return $denied;
        }
        $validated = $request->validate([
            'question_text' => ['sometimes', 'required', 'string', 'min:10', 'max:5000'],
            'question_type' => ['sometimes', Rule::in(config('question_generation.question_types'))],
            'marks' => ['sometimes', 'numeric', 'gt:0', 'max:' . config('question_generation.max_marks_per_question')],
            'difficulty_level' => ['sometimes', 'nullable', Rule::in(config('question_generation.difficulty_levels'))],
            'cognitive_level' => ['sometimes', 'nullable', Rule::in(config('question_generation.cognitive_levels'))],
            'learning_outcome_id' => ['sometimes', 'nullable', 'integer'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'nullable', 'array', 'max:8'],
            'options.*' => ['string', 'max:500'],
            'correct_option' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_answer' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'explanation' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expected_version' => ['sometimes', 'nullable', 'integer'],
        ]);

        // STEP 34: version-aware editing — reject stale edits instead of overwriting a collaborator's change.
        if (isset($validated['expected_version']) && (int) $validated['expected_version'] !== (int) $generatedQuestion->version) {
            return response()->json([
                'status' => 'error',
                'message' => 'This draft was edited by someone else since you loaded it (now v' . $generatedQuestion->version . '). Reload before saving.',
                'data' => $this->questionPayload($generatedQuestion),
            ], 409);
        }
        unset($validated['expected_version']);

        try {
            $updated = $this->service->update($generatedQuestion, $request->user(), $validated);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Draft updated.', 'data' => $this->questionPayload($updated)]);
    }

    /** POST /api/generated-questions/{generatedQuestion}/approve */
    public function approve(Request $request, GeneratedQuestion $generatedQuestion): JsonResponse
    {
        if ($denied = $this->deny($request, $generatedQuestion)) {
            return $denied;
        }
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        try {
            $q = $this->service->approve($generatedQuestion, $request->user(), $validated['note'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Question approved. You can now add it to an assessment.', 'data' => $this->questionPayload($q)]);
    }

    /** POST /api/generated-questions/{generatedQuestion}/reject */
    public function reject(Request $request, GeneratedQuestion $generatedQuestion): JsonResponse
    {
        if ($denied = $this->deny($request, $generatedQuestion)) {
            return $denied;
        }
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        try {
            $q = $this->service->reject($generatedQuestion, $request->user(), $validated['note'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Question rejected.', 'data' => $this->questionPayload($q)]);
    }

    /** POST /api/generated-questions/{generatedQuestion}/regenerate */
    public function regenerateQuestion(Request $request, GeneratedQuestion $generatedQuestion): JsonResponse
    {
        if ($denied = $this->deny($request, $generatedQuestion)) {
            return $denied;
        }
        $validated = $request->validate($this->feedbackRules());

        try {
            $q = $this->service->regenerateQuestion($generatedQuestion, $request->user(), $this->feedbackList($validated));
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (Exception $e) {
            Log::warning("QuestionGenerationController: regenerate failed for draft {$generatedQuestion->id}: {$e->getMessage()}");

            return response()->json(['status' => 'error', 'message' => 'The question generator could not produce a replacement draft right now. Please try again.'], 503);
        }

        return response()->json(['status' => 'success', 'message' => 'A new draft was generated; the previous draft was kept as rejected.', 'data' => $this->questionPayload($q)], 201);
    }

    /** POST /api/generated-questions/{generatedQuestion}/add-to-assessment */
    public function addToAssessment(Request $request, GeneratedQuestion $generatedQuestion): JsonResponse
    {
        if ($denied = $this->deny($request, $generatedQuestion)) {
            return $denied;
        }
        $validated = $request->validate(['assessment_id' => ['nullable', 'integer']]);

        try {
            $official = $this->service->addToAssessment($generatedQuestion, $request->user(), isset($validated['assessment_id']) ? (int) $validated['assessment_id'] : null);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Official assessment question created.',
            'data' => ['question' => $this->officialPayload($official), 'generated_question' => $this->questionPayload($generatedQuestion->fresh())],
        ], 201);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * STEP 34: drafts belong to the course; edit/approve/reject/regenerate/add need approve_generated_question
     * (OWNER/EDITOR). Reviewers and viewers read them and discuss via comments.
     */
    protected function deny(Request $request, GeneratedQuestion $q, string $ability = 'approve_generated_question'): ?JsonResponse
    {
        $q->loadMissing('request.course');
        if (!$q->request || !$this->access()->can($request->user(), $q->request->course, $ability)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to generated question.'], 403);
        }

        return null;
    }

    protected function access(): \App\Services\CourseAccessService
    {
        return app(\App\Services\CourseAccessService::class);
    }

    protected function feedbackRules(): array
    {
        return [
            'feedback' => ['nullable', 'array', 'max:8'],
            'feedback.*' => [Rule::in(config('question_generation.feedback_reasons'))],
            'feedback_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function feedbackList(array $validated): array
    {
        $labels = ['too_easy' => 'Too easy', 'too_difficult' => 'Too difficult', 'wrong_topic' => 'Wrong topic', 'wrong_co' => 'Does not match the course outcome',
            'too_similar' => 'Too similar to existing questions', 'poor_wording' => 'Poor wording', 'not_appropriate' => 'Not academically appropriate', 'other' => 'Other'];
        $out = array_map(fn ($k) => $labels[$k] ?? $k, $validated['feedback'] ?? []);
        if (!empty($validated['feedback_note'])) {
            $out[] = trim($validated['feedback_note']);
        }

        return $out;
    }

    protected function requestPayload(QuestionGenerationRequest $r, bool $withQuestions = false): array
    {
        $payload = [
            'id' => $r->id,
            'course_id' => $r->course_id,
            'assessment_id' => $r->assessment_id,
            'course' => $r->relationLoaded('course') && $r->course ? ['id' => $r->course->id, 'course_code' => $r->course->course_code, 'course_name' => $r->course->course_name] : null,
            'assessment' => $r->relationLoaded('assessment') && $r->assessment ? ['id' => $r->assessment->id, 'title' => $r->assessment->title, 'total_marks' => $r->assessment->total_marks ?? null,
                'remaining_marks' => isset($r->assessment->total_marks) ? $this->service->remainingMarks($r->assessment) : null] : null,
            'learning_outcome' => $r->relationLoaded('learningOutcome') && $r->learningOutcome ? ['id' => $r->learningOutcome->id, 'code' => $r->learningOutcome->code, 'description' => $r->learningOutcome->description] : null,
            'program_outcome' => $r->relationLoaded('programOutcome') && $r->programOutcome ? ['id' => $r->programOutcome->id, 'code' => $r->programOutcome->code, 'title' => $r->programOutcome->title] : null,
            'topic' => $r->topic,
            'question_type' => $r->question_type,
            'difficulty_level' => $r->difficulty_level,
            'cognitive_level' => $r->cognitive_level,
            'marks' => $r->marks,
            'number_of_questions' => $r->number_of_questions,
            'language' => $r->language,
            'document_scope' => $r->document_scope,
            'blueprint' => $r->blueprint,
            'include_expected_answer' => $r->include_expected_answer,
            'include_explanation' => $r->include_explanation,
            'generation_status' => $r->generation_status,
            'generation_method' => $r->generation_method,
            'models' => ['generation' => $r->generation_model, 'generation_version' => $r->generation_model_version, 'embedding' => $r->embedding_model, 'prompt_version' => $r->prompt_version],
            'regeneration_count' => $r->regeneration_count,
            'max_regenerations' => (int) config('question_generation.max_regenerations_per_request'),
            'feedback' => $r->feedback ?? [],
            'warnings' => $r->warnings ?? [],
            'set_summary' => $r->set_summary,
            'blueprint_summary' => $r->blueprint_summary,
            'retrieved_chunks' => $r->retrieved_chunks,
            'existing_questions_count' => $r->existing_questions_count,
            'error_message' => $r->error_message,
            'generated_questions_count' => $r->generated_questions_count ?? ($r->relationLoaded('generatedQuestions') ? $r->generatedQuestions->count() : null),
            'approved_count' => $r->approved_count ?? ($r->relationLoaded('generatedQuestions') ? $r->generatedQuestions->where('review_status', GeneratedQuestion::REVIEW_APPROVED)->count() : null),
            'disclaimer' => QuestionGenerationService::DISCLAIMER,
            'completed_at' => $r->completed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
        if ($withQuestions && $r->relationLoaded('generatedQuestions')) {
            $payload['questions'] = $r->generatedQuestions->map(fn ($q) => $this->questionPayload($q))->values();
        }

        return $payload;
    }

    protected function questionPayload(GeneratedQuestion $q): array
    {
        return [
            'id' => $q->id,
            'generation_request_id' => $q->generation_request_id,
            'sequence' => $q->sequence,
            'question_text' => $q->question_text,
            'original_question_text' => $q->original_question_text,
            'is_edited' => $q->question_text !== $q->original_question_text || $q->version > 1,
            'question_type' => $q->question_type,
            'marks' => $q->marks,
            'difficulty_level' => $q->difficulty_level,
            'cognitive_level' => $q->cognitive_level,
            'learning_outcome_id' => $q->learning_outcome_id,
            'program_outcome_id' => $q->program_outcome_id,
            'topic' => $q->topic,
            'options' => $q->options,
            'correct_option' => $q->correct_option,
            'expected_answer' => $q->expected_answer,
            'explanation' => $q->explanation,
            'source_chunk_ids' => $q->source_chunk_ids ?? [],
            'validation' => $q->validation,
            'validation_status' => $q->validation_status,
            'review_status' => $q->review_status,
            'review_note' => $q->review_note,
            'version' => $q->version,
            'edited_by' => $q->edited_by,
            'edited_at' => $q->edited_at?->toIso8601String(),
            'approved_by' => $q->approved_by,
            'approved_at' => $q->approved_at?->toIso8601String(),
            'regenerated_from_id' => $q->regenerated_from_id,
            'official_question_id' => $q->official_question_id,
            'added_to_assessment_at' => $q->added_to_assessment_at?->toIso8601String(),
            'can_add_to_assessment' => $q->review_status === GeneratedQuestion::REVIEW_APPROVED && !$q->official_question_id,
            'created_at' => $q->created_at?->toIso8601String(),
            'updated_at' => $q->updated_at?->toIso8601String(),
        ];
    }

    protected function officialPayload(Question $q): array
    {
        return [
            'id' => $q->id, 'assessment_id' => $q->assessment_id, 'question_number' => $q->question_number, 'question_text' => $q->question_text,
            'question_type' => $q->question_type, 'marks' => $q->marks, 'difficulty_level' => $q->difficulty_level, 'cognitive_level' => $q->cognitive_level,
            'learning_outcome_id' => $q->learning_outcome_id,
        ];
    }
}
