<?php

namespace App\Services;

use App\Jobs\GenerateQuestionsJob;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\GeneratedQuestion;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 33: Constrained Question Generator orchestration.
 *
 * createRequest(): authorize every referenced entity, persist the request, queue generation.
 * run(): build the grounded payload (course, CO, PO, retrieved document chunks, existing questions),
 *        call the AI service, validate its output strictly, persist drafts. No long transactions around AI calls.
 * Review actions (edit/approve/reject/regenerate/add-to-assessment) keep faculty in control: only an APPROVED
 * draft can become an official `questions` row, and only through an explicit action.
 */
class QuestionGenerationService
{
    public const DISCLAIMER = 'AI-generated questions are drafts. Review the wording, factual accuracy, difficulty, cognitive demand, course-outcome alignment, marks, and academic appropriateness before using them in an assessment.';

    private const TYPE_TO_AI = [
        'mcq' => 'MCQ', 'short_answer' => 'SHORT_ANSWER', 'descriptive' => 'DESCRIPTIVE', 'problem_solving' => 'PROBLEM_SOLVING',
        'true_false' => 'TRUE_FALSE', 'conceptual' => 'CONCEPTUAL', 'analytical' => 'ANALYTICAL', 'other' => 'DESCRIPTIVE',
    ];
    private const TYPE_FROM_AI = [
        'MCQ' => 'mcq', 'SHORT_ANSWER' => 'short_answer', 'DESCRIPTIVE' => 'descriptive', 'PROBLEM_SOLVING' => 'problem_solving',
        'TRUE_FALSE' => 'true_false', 'CONCEPTUAL' => 'conceptual', 'ANALYTICAL' => 'analytical',
    ];

    public function __construct(
        protected AiService $aiService,
        protected AcademicDocumentRetrievalService $retrieval,
        protected AuditLogService $audit,
    ) {}

    // ------------------------------------------------------------------ request lifecycle

    /**
     * @param array<string, mixed> $input validated request input
     */
    public function createRequest(User $user, array $input): QuestionGenerationRequest
    {
        $course = Course::find($input['course_id'] ?? null);
        if (!$course || !app(CourseAccessService::class)->can($user, $course, 'generate_questions')) {
            throw new HttpException(403, 'Unauthorized access to course.');
        }

        $assessment = null;
        if (!empty($input['assessment_id'])) {
            $assessment = Assessment::find($input['assessment_id']);
            if (!$assessment || $assessment->course_id !== $course->id) {
                throw new HttpException(403, 'Unauthorized access to assessment.');
            }
        }

        if (!empty($input['learning_outcome_id'])) {
            $lo = LearningOutcome::find($input['learning_outcome_id']);
            if (!$lo || $lo->course_id !== $course->id) {
                throw new HttpException(422, 'The selected course outcome does not belong to this course.');
            }
        }

        if (!empty($input['program_outcome_id'])) {
            $po = ProgramOutcome::find($input['program_outcome_id']);
            if (!$po || !$course->program_id || $po->program_id !== $course->program_id) {
                throw new HttpException(422, 'The selected program outcome does not belong to this course\'s program.');
            }
        }

        $scope = $this->normalizeDocumentScope($user, $course, $assessment, $input['document_scope'] ?? null);

        $warnings = [];
        $blueprint = $input['blueprint'] ?? null;
        $count = $blueprint ? array_sum(array_column($blueprint, 'count')) : (int) $input['number_of_questions'];
        $max = (int) config('question_generation.max_questions_per_request');
        if ($count > $max) {
            throw new HttpException(422, "A single request may generate at most {$max} questions.");
        }
        if ($assessment) {
            $remaining = $this->remainingMarks($assessment);
            $requested = $blueprint
                ? array_sum(array_map(fn ($s) => ($s['marks'] ?? $input['marks']) * $s['count'], $blueprint))
                : $count * (float) $input['marks'];
            if ($requested > $remaining) {
                $warnings[] = "Requested marks ({$requested}) exceed the remaining assessment allocation ({$remaining}). Approved questions can still be added individually while marks remain.";
            }
        }

        $request = QuestionGenerationRequest::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'assessment_id' => $assessment?->id,
            'topic' => isset($input['topic']) ? Str::limit(trim($input['topic']), 255, '') : null,
            'learning_outcome_id' => $input['learning_outcome_id'] ?? null,
            'program_outcome_id' => $input['program_outcome_id'] ?? null,
            'question_type' => $input['question_type'],
            'difficulty_level' => $input['difficulty_level'] ?? null,
            'cognitive_level' => $input['cognitive_level'] ?? null,
            'marks' => $input['marks'],
            'number_of_questions' => $count,
            'language' => $input['language'] ?? 'English',
            'document_scope' => $scope,
            'blueprint' => $blueprint,
            'include_expected_answer' => (bool) ($input['include_expected_answer'] ?? true),
            'include_explanation' => (bool) ($input['include_explanation'] ?? false),
            'generation_status' => QuestionGenerationRequest::STATUS_PENDING,
            'warnings' => $warnings ?: null,
            'prompt_version' => config('question_generation.prompt_version'),
        ]);

        $this->audit->log('QUESTION_GENERATION_REQUESTED', $request, $request->id, [
            'course_id' => $course->id, 'assessment_id' => $assessment?->id, 'question_type' => $request->question_type,
            'number_of_questions' => $count, 'learning_outcome_id' => $request->learning_outcome_id,
        ], $user);

        GenerateQuestionsJob::dispatch($request->id);

        return $request->fresh();
    }

    /**
     * Executed by GenerateQuestionsJob. Idempotent: a COMPLETED request is not re-run.
     */
    public function run(QuestionGenerationRequest $request): void
    {
        $request = $request->fresh(['course', 'assessment', 'learningOutcome', 'programOutcome', 'user']);
        if (!$request || $request->generation_status === QuestionGenerationRequest::STATUS_COMPLETED
            || $request->generation_status === QuestionGenerationRequest::STATUS_CANCELLED) {
            return;
        }

        $request->update(['generation_status' => QuestionGenerationRequest::STATUS_PROCESSING, 'error_message' => null]);

        try {
            $payload = $this->buildPayload($request);
            $response = $this->aiService->generateQuestions($payload);
            $drafts = $this->validateResponse($response, $request);

            DB::transaction(function () use ($request, $response, $drafts, $payload) {
                $start = (int) $request->generatedQuestions()->max('sequence');
                foreach ($drafts as $i => $draft) {
                    $request->generatedQuestions()->create($draft + ['sequence' => $start + $i + 1]);
                }
                $request->update([
                    'generation_status' => QuestionGenerationRequest::STATUS_COMPLETED,
                    'generation_method' => $response['generation_method'] ?? null,
                    'generation_model' => $response['model'],
                    'generation_model_version' => $response['model_version'] ?? null,
                    'embedding_model' => $response['embedding_model'] ?? null,
                    'prompt_version' => $response['prompt_version'],
                    'warnings' => array_values(array_unique(array_merge($request->warnings ?? [], $response['warnings'] ?? []))) ?: null,
                    'blueprint_summary' => $response['blueprint_summary'] ?? null,
                    'set_summary' => $this->setSummary($request->fresh('generatedQuestions')),
                    'retrieved_chunks' => count($payload['document_context']),
                    'existing_questions_count' => count($payload['existing_question_context']),
                    'completed_at' => now(),
                ]);
            });

            $this->audit->log('QUESTION_GENERATED', $request, $request->id, [
                'generated_count' => count($drafts), 'generation_method' => $response['generation_method'] ?? null,
                'model' => $response['model'], 'prompt_version' => $response['prompt_version'],
            ], $request->user);
        } catch (Exception $e) {
            Log::warning("QuestionGenerationService: request {$request->id} failed: {$e->getMessage()}");
            $request->update([
                'generation_status' => QuestionGenerationRequest::STATUS_FAILED,
                'error_message' => 'The question generator could not produce drafts. Please try again.',
            ]);
            throw $e;
        }
    }

    public function regenerateRequest(QuestionGenerationRequest $request, User $user, array $feedback = []): QuestionGenerationRequest
    {
        $this->assertRegenerationAllowed($request);
        if (in_array($request->generation_status, [QuestionGenerationRequest::STATUS_PENDING, QuestionGenerationRequest::STATUS_PROCESSING], true)) {
            throw new HttpException(409, 'This request is still being processed.');
        }

        DB::transaction(function () use ($request, $feedback) {
            $request->generatedQuestions()
                ->whereIn('review_status', [GeneratedQuestion::REVIEW_DRAFT, GeneratedQuestion::REVIEW_REVIEWED])
                ->update(['review_status' => GeneratedQuestion::REVIEW_REJECTED, 'review_note' => 'Superseded by regeneration.']);
            $request->update([
                'generation_status' => QuestionGenerationRequest::STATUS_PENDING,
                'regeneration_count' => $request->regeneration_count + 1,
                'feedback' => array_values(array_merge($request->feedback ?? [], $feedback)),
                'error_message' => null,
            ]);
        });

        $this->audit->log('QUESTION_REGENERATED', $request, $request->id, ['scope' => 'request', 'feedback' => $feedback], $user);
        GenerateQuestionsJob::dispatch($request->id);

        return $request->fresh();
    }

    /**
     * Regenerate a single draft synchronously (count = 1). The old draft is kept (REJECTED) for history.
     */
    public function regenerateQuestion(GeneratedQuestion $question, User $user, array $feedback = []): GeneratedQuestion
    {
        $request = $question->request()->with(['course', 'assessment', 'learningOutcome', 'programOutcome'])->firstOrFail();
        $this->assertRegenerationAllowed($request);
        if ($question->review_status === GeneratedQuestion::REVIEW_APPROVED) {
            throw new HttpException(422, 'Approved questions cannot be regenerated. Reject it first if needed.');
        }

        $payload = $this->buildPayload($request, $feedback, 1, $question);
        $response = $this->aiService->generateQuestions($payload);
        $drafts = $this->validateResponse($response, $request);
        if ($drafts === []) {
            throw new Exception('The generator returned no usable draft.');
        }

        $new = DB::transaction(function () use ($request, $question, $drafts, $response, $feedback) {
            $question->update(['review_status' => GeneratedQuestion::REVIEW_REJECTED, 'review_note' => 'Regenerated: ' . (implode(', ', $feedback) ?: 'faculty request')]);
            $new = $request->generatedQuestions()->create($drafts[0] + [
                'sequence' => $question->sequence,
                'regenerated_from_id' => $question->id,
            ]);
            $request->update([
                'regeneration_count' => $request->regeneration_count + 1,
                'feedback' => array_values(array_merge($request->feedback ?? [], $feedback)),
                'generation_model' => $response['model'],
                'set_summary' => $this->setSummary($request->fresh('generatedQuestions')),
            ]);

            return $new;
        });

        $this->audit->log('QUESTION_REGENERATED', $new, $new->id, ['request_id' => $request->id, 'replaced_id' => $question->id, 'feedback' => $feedback], $user);

        return $new;
    }

    // ------------------------------------------------------------------ review actions

    public function update(GeneratedQuestion $question, User $user, array $data): GeneratedQuestion
    {
        if ($question->review_status === GeneratedQuestion::REVIEW_APPROVED && $question->official_question_id) {
            throw new HttpException(422, 'This question was already added to an assessment; edit it there.');
        }

        $changes = array_intersect_key($data, array_flip(['question_text', 'question_type', 'marks', 'difficulty_level', 'cognitive_level', 'learning_outcome_id', 'topic', 'options', 'correct_option', 'expected_answer', 'explanation']));
        if (isset($changes['learning_outcome_id'])) {
            $lo = LearningOutcome::find($changes['learning_outcome_id']);
            if (!$lo || $lo->course_id !== $question->request->course_id) {
                throw new HttpException(422, 'The selected course outcome does not belong to this course.');
            }
        }

        $question->fill($changes);
        $question->version = $question->version + 1;
        $question->edited_by = $user->id;
        $question->edited_at = now();
        if ($question->review_status === GeneratedQuestion::REVIEW_DRAFT) {
            $question->review_status = GeneratedQuestion::REVIEW_REVIEWED;
        }
        if ($question->review_status === GeneratedQuestion::REVIEW_APPROVED) {
            // Edits after approval require re-approval.
            $question->review_status = GeneratedQuestion::REVIEW_REVIEWED;
            $question->approved_at = null;
            $question->approved_by = null;
        }
        $question->save();

        $this->audit->log('QUESTION_EDITED', $question, $question->id, ['version' => $question->version, 'fields' => array_keys($changes)], $user);

        return $question->fresh();
    }

    public function approve(GeneratedQuestion $question, User $user, ?string $note = null): GeneratedQuestion
    {
        if ($question->review_status === GeneratedQuestion::REVIEW_REJECTED) {
            throw new HttpException(422, 'A rejected question cannot be approved. Regenerate or edit it first.');
        }
        $question->update([
            'review_status' => GeneratedQuestion::REVIEW_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'review_note' => $note,
        ]);
        $this->audit->log('QUESTION_APPROVED', $question, $question->id, ['validation_status' => $question->validation_status], $user);

        return $question->fresh();
    }

    public function reject(GeneratedQuestion $question, User $user, ?string $note = null): GeneratedQuestion
    {
        if ($question->official_question_id) {
            throw new HttpException(422, 'This question was already added to an assessment.');
        }
        $question->update([
            'review_status' => GeneratedQuestion::REVIEW_REJECTED,
            'review_note' => $note,
            'approved_at' => null,
            'approved_by' => null,
        ]);
        $this->audit->log('QUESTION_REJECTED', $question, $question->id, ['note' => $note], $user);

        return $question->fresh();
    }

    /**
     * Create the official assessment question from an APPROVED draft. Explicit faculty action only.
     */
    public function addToAssessment(GeneratedQuestion $question, User $user, ?int $assessmentId = null): Question
    {
        if ($question->review_status !== GeneratedQuestion::REVIEW_APPROVED) {
            throw new HttpException(422, 'Only approved questions can be added to an assessment.');
        }
        if ($question->official_question_id) {
            throw new HttpException(422, 'This question has already been added to an assessment.');
        }

        $request = $question->request;
        $assessment = Assessment::with('course')->find($assessmentId ?? $request->assessment_id);
        if (!$assessment || !$assessment->course || !app(CourseAccessService::class)->can($user, $assessment->course, 'edit_question')) {
            throw new HttpException(403, 'Unauthorized access to assessment.');
        }
        if ($assessment->course_id !== $request->course_id) {
            throw new HttpException(422, 'The assessment belongs to a different course than the generated question.');
        }
        $remaining = $this->remainingMarks($assessment);
        if ($question->marks > $remaining) {
            throw new HttpException(422, "Adding this question ({$question->marks} marks) would exceed the assessment total; {$remaining} marks remain.");
        }

        $official = DB::transaction(function () use ($question, $assessment) {
            $validation = $question->validation ?? [];
            $official = Question::create([
                'assessment_id' => $assessment->id,
                'question_number' => ((int) $assessment->questions()->max('question_number')) + 1,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'marks' => $question->marks,
                'difficulty_level' => $question->difficulty_level ?: 'medium',
                'cognitive_level' => $question->cognitive_level ?: 'Understand',
                'learning_outcome_id' => $question->learning_outcome_id,
                'expected_answer' => $question->expected_answer,
                'ai_question_type' => $validation['detected_question_type'] ?? null,
                'ai_difficulty_level' => isset($validation['detected_difficulty']) ? strtolower($validation['detected_difficulty']) : null,
                'ai_cognitive_level' => isset($validation['detected_cognitive_level']) ? ucfirst(strtolower($validation['detected_cognitive_level'])) : null,
                'ai_topics' => $validation['detected_topics'] ?? null,
                'ai_analysis_status' => isset($validation['detected_question_type']) ? 'completed' : 'pending',
                'ai_analyzed_at' => isset($validation['detected_question_type']) ? now() : null,
            ]);
            $question->update(['official_question_id' => $official->id, 'added_to_assessment_at' => now()]);

            return $official;
        });

        CoPoMappingValidatorService::invalidateCacheForAssessment($assessment->id);
        StudentPerformanceService::invalidateCache($assessment->id);

        $this->audit->log('QUESTION_ADDED_TO_ASSESSMENT', $official, $official->id, [
            'generated_question_id' => $question->id, 'assessment_id' => $assessment->id, 'marks' => $question->marks,
        ], $user);

        return $official;
    }

    // ------------------------------------------------------------------ payload

    /**
     * @param string[] $feedback
     */
    public function buildPayload(QuestionGenerationRequest $request, array $feedback = [], ?int $count = null, ?GeneratedQuestion $replacing = null): array
    {
        $course = $request->course;
        $lo = $request->learningOutcome;
        $po = $request->programOutcome;
        $user = $request->user ?? User::find($request->user_id);

        $query = trim(($request->topic ?? '') . ' ' . ($lo?->description ?? '') . ' ' . ($course->course_name ?? ''));
        $chunks = [];
        if ($user && $query !== '') {
            try {
                $retrieved = $this->retrieval->retrieveForScope($user, $request->document_scope ?? ['scope_type' => 'COURSE', 'course_id' => $course->id], $query,
                    (int) config('question_generation.document_top_k'), (float) config('question_generation.document_min_relevance'));
                $chunks = array_map(fn ($c) => [
                    'chunk_id' => $c['chunk_id'], 'document_id' => $c['document_id'], 'document_name' => $c['document_name'],
                    'content' => $c['content'], 'page_number' => $c['page_number'], 'section_title' => $c['section_title'],
                    'similarity_score' => $c['similarity_score'],
                ], $retrieved['chunks']);
            } catch (Exception $e) {
                Log::warning("QuestionGenerationService: retrieval skipped for request {$request->id}: {$e->getMessage()}");
            }
        }

        $existing = $this->existingQuestions($request, $replacing);
        $allFeedback = array_values(array_filter(array_merge($request->feedback ?? [], $feedback)));
        if ($replacing) {
            $allFeedback[] = 'Replace this rejected draft with a different question: ' . Str::limit($replacing->question_text, 300, '');
        }

        return [
            'course_context' => ['course_code' => $course->course_code, 'course_name' => $course->course_name, 'description' => $course->description],
            'learning_outcome' => $lo ? ['code' => $lo->code, 'description' => $lo->description, 'cognitive_level' => $lo->cognitive_level] : null,
            'program_outcome' => $po ? ['code' => $po->code, 'title' => $po->title, 'description' => $po->description] : null,
            'topic' => $request->topic,
            'question_type' => self::TYPE_TO_AI[$request->question_type] ?? 'DESCRIPTIVE',
            'difficulty_level' => $request->difficulty_level ? strtoupper($request->difficulty_level) : null,
            'cognitive_level' => $request->cognitive_level ? strtoupper($request->cognitive_level) : null,
            'marks' => (float) $request->marks,
            'number_of_questions' => $count ?? (int) $request->number_of_questions,
            'language' => $request->language,
            'include_expected_answer' => $request->include_expected_answer,
            'include_explanation' => $request->include_explanation,
            'document_context' => $chunks,
            'existing_question_context' => $existing,
            'blueprint' => $count ? null : $this->blueprintForAi($request->blueprint),
            'feedback' => array_slice($allFeedback, -10),
            'similarity_thresholds' => config('question_generation.similarity_thresholds'),
            'alignment_thresholds' => config('question_generation.alignment_thresholds'),
        ];
    }

    protected function blueprintForAi(?array $blueprint): ?array
    {
        if (!$blueprint) {
            return null;
        }

        return array_map(fn ($s) => [
            'difficulty_level' => isset($s['difficulty_level']) ? strtoupper($s['difficulty_level']) : null,
            'cognitive_level' => isset($s['cognitive_level']) ? strtoupper($s['cognitive_level']) : null,
            'question_type' => isset($s['question_type']) ? (self::TYPE_TO_AI[$s['question_type']] ?? null) : null,
            'marks' => $s['marks'] ?? null,
            'count' => (int) ($s['count'] ?? 1),
        ], $blueprint);
    }

    /**
     * Assessment questions, all course questions (question bank) and previous questions — owner-scoped.
     */
    protected function existingQuestions(QuestionGenerationRequest $request, ?GeneratedQuestion $replacing = null): array
    {
        $max = (int) config('question_generation.max_existing_questions');
        $out = [];

        $courseQuestions = Question::query()
            ->join('assessments', 'assessments.id', '=', 'questions.assessment_id')
            ->where('assessments.course_id', $request->course_id)
            ->orderByDesc('questions.id')
            ->limit($max)
            ->get(['questions.id as qid', 'questions.question_text as qtext', 'questions.assessment_id as aid', 'questions.question_number as qnum', 'assessments.title as atitle']);
        foreach ($courseQuestions as $q) {
            $out[] = ['id' => $q->qid, 'text' => $q->qtext, 'source' => $q->aid === $request->assessment_id ? 'assessment' : 'question_bank', 'label' => "{$q->atitle} Q{$q->qnum}"];
        }

        $previous = PreviousQuestion::where('course_id', $request->course_id)->orderByDesc('id')->limit(max(0, $max - count($out)))->get(['id', 'question_text', 'source_year', 'source_assessment']);
        foreach ($previous as $p) {
            $out[] = ['id' => $p->id, 'text' => $p->question_text, 'source' => 'previous', 'label' => trim(($p->source_assessment ?? 'Previous') . ' ' . ($p->source_year ?? ''))];
        }

        // Approved drafts in this request also count as "existing" so regeneration avoids them.
        foreach ($request->generatedQuestions()->where('review_status', GeneratedQuestion::REVIEW_APPROVED)->get(['id', 'question_text', 'sequence']) as $g) {
            if (!$replacing || $g->id !== $replacing->id) {
                $out[] = ['id' => $g->id, 'text' => $g->question_text, 'source' => 'approved_draft', 'label' => "Draft {$g->sequence}"];
            }
        }

        return array_slice(array_filter($out, fn ($e) => trim((string) $e['text']) !== ''), 0, $max);
    }

    // ------------------------------------------------------------------ AI output validation

    /**
     * Strictly validate the AI response; invalid items are dropped, never persisted.
     *
     * @return array<int, array<string, mixed>> attributes for GeneratedQuestion::create
     */
    public function validateResponse(array $response, QuestionGenerationRequest $request): array
    {
        $maxMarks = (float) config('question_generation.max_marks_per_question');
        $difficulties = config('question_generation.difficulty_levels');
        $cognitive = config('question_generation.cognitive_levels');
        $drafts = [];

        foreach ($response['questions'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['question_text'] ?? ''));
            $type = self::TYPE_FROM_AI[strtoupper((string) ($item['question_type'] ?? ''))] ?? null;
            $marks = is_numeric($item['marks'] ?? null) ? (float) $item['marks'] : null;
            if (mb_strlen($text) < 10 || !$type || $marks === null || $marks <= 0 || $marks > $maxMarks) {
                Log::info("QuestionGenerationService: dropped invalid AI draft for request {$request->id}.");
                continue;
            }
            $difficulty = isset($item['difficulty_level']) ? strtolower((string) $item['difficulty_level']) : null;
            $cog = isset($item['cognitive_level']) ? ucfirst(strtolower((string) $item['cognitive_level'])) : null;
            $validation = is_array($item['validation'] ?? null) ? $item['validation'] : [];
            $status = $validation['overall_status'] ?? GeneratedQuestion::VALIDATION_PENDING;
            if (!in_array($status, [GeneratedQuestion::VALIDATION_PASSED, GeneratedQuestion::VALIDATION_WARNINGS, GeneratedQuestion::VALIDATION_FAILED], true)) {
                $status = GeneratedQuestion::VALIDATION_PENDING;
            }
            $options = is_array($item['options'] ?? null) ? array_values(array_map(fn ($o) => Str::limit((string) $o, 500, ''), $item['options'])) : null;

            $drafts[] = [
                'question_text' => Str::limit($text, 5000, ''),
                'original_question_text' => Str::limit($text, 5000, ''),
                'question_type' => $type,
                'marks' => $marks,
                'difficulty_level' => in_array($difficulty, $difficulties, true) ? $difficulty : $request->difficulty_level,
                'cognitive_level' => in_array($cog, $cognitive, true) ? $cog : $request->cognitive_level,
                'learning_outcome_id' => $request->learning_outcome_id,
                'program_outcome_id' => $request->program_outcome_id,
                'topic' => isset($item['topic']) ? Str::limit((string) $item['topic'], 255, '') : $request->topic,
                'options' => $options,
                'correct_option' => isset($item['correct_option']) ? Str::limit((string) $item['correct_option'], 500, '') : null,
                'expected_answer' => $request->include_expected_answer && isset($item['expected_answer']) ? Str::limit((string) $item['expected_answer'], 5000, '') : null,
                'explanation' => isset($item['explanation']) ? Str::limit((string) $item['explanation'], 2000, '') : null,
                'source_chunk_ids' => array_values(array_filter((array) ($item['source_chunk_ids'] ?? []), 'is_int')),
                'validation' => $validation,
                'validation_status' => $status,
                'review_status' => GeneratedQuestion::REVIEW_DRAFT,
                'version' => 1,
            ];
        }

        if ($drafts === [] && $response['questions'] !== []) {
            throw new Exception('AI Service returned no valid question drafts.');
        }

        return $drafts;
    }

    // ------------------------------------------------------------------ helpers

    public function remainingMarks(Assessment $assessment): float
    {
        return round((float) $assessment->total_marks - (float) $assessment->questions()->sum('marks'), 2);
    }

    /**
     * Set-level overview (difficulty / Bloom / CO distribution, duplicates) for faculty review — not a quality verdict.
     */
    public function setSummary(QuestionGenerationRequest $request): array
    {
        $active = $request->generatedQuestions->filter(fn ($q) => $q->review_status !== GeneratedQuestion::REVIEW_REJECTED);
        $n = max(1, $active->count());
        $dist = fn (string $field) => $active->groupBy(fn ($q) => $q->{$field} ?: 'unspecified')->map->count()->sortKeys()->all();

        return [
            'total' => $active->count(),
            'difficulty' => $dist('difficulty_level'),
            'cognitive_level' => $dist('cognitive_level'),
            'question_type' => $dist('question_type'),
            'validation' => $dist('validation_status'),
            'potential_duplicates' => $active->filter(fn ($q) => ($q->validation['similarity_status'] ?? null) === 'POTENTIAL_DUPLICATE')->count(),
            'weak_alignment' => $active->filter(fn ($q) => in_array($q->validation['co_alignment_status'] ?? null, ['WEAK', 'NOT_ALIGNED'], true))->count(),
            'total_marks' => round((float) $active->sum('marks'), 2),
            'co_coverage' => $active->groupBy(fn ($q) => $q->learning_outcome_id ?: 'none')->map(fn ($g) => round($g->count() * 100 / $n))->all(),
        ];
    }

    protected function assertRegenerationAllowed(QuestionGenerationRequest $request): void
    {
        $max = (int) config('question_generation.max_regenerations_per_request');
        if ($request->regeneration_count >= $max) {
            throw new HttpException(422, "This request has reached the regeneration limit ({$max}). Create a new request with adjusted constraints.");
        }
    }

    /**
     * @return array{scope_type:string, course_id:int, document_id?:int|null, assessment_id?:int|null}
     */
    protected function normalizeDocumentScope(User $user, Course $course, ?Assessment $assessment, ?array $scope): array
    {
        $type = strtoupper((string) ($scope['scope_type'] ?? 'COURSE'));
        if ($type === 'DOCUMENT') {
            $doc = DocumentProcessing::find($scope['document_id'] ?? null);
            if (!$doc || $doc->course_id !== $course->id || !$user->can('view', $doc)) {
                throw new HttpException(403, 'Unauthorized access to document.');
            }

            return ['scope_type' => 'DOCUMENT', 'course_id' => $course->id, 'document_id' => $doc->id];
        }
        if ($type === 'ASSESSMENT') {
            $target = $assessment ?? Assessment::find($scope['assessment_id'] ?? null);
            if (!$target || $target->course_id !== $course->id) {
                throw new HttpException(403, 'Unauthorized access to assessment.');
            }

            return ['scope_type' => 'ASSESSMENT', 'course_id' => $course->id, 'assessment_id' => $target->id];
        }

        return ['scope_type' => 'COURSE', 'course_id' => $course->id];
    }
}
