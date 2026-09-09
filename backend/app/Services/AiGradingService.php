<?php

namespace App\Services;

use App\Jobs\GradeAnswerJob;
use App\Models\AiGradingCriterionResult;
use App\Models\AiGradingResult;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * STEP 27: AI Grading Assistance orchestration.
 *
 * AI output is a recommendation only. It is validated against the approved rubric before
 * it is stored, kept separate from faculty marks, and never finalizes a grade.
 */
class AiGradingService
{
    public const MARK_TOLERANCE = 0.005;

    public const STATUS_AI_UNAVAILABLE = 503;
    public const STATUS_AI_TIMEOUT = 504;
    public const STATUS_AI_INVALID = 502;
    public const STATUS_VALIDATION = 422;
    public const STATUS_CONFLICT = 409;

    public const MSG_NO_RUBRIC = 'No approved rubric is available for this question. Create or approve a rubric before requesting AI grading assistance.';
    public const MSG_IMAGE_UNSUPPORTED = 'Image-based AI grading is not currently supported. Add the answer text to request AI grading assistance.';
    public const MSG_INVALID_AI = 'FacultyLens could not validate the AI grading result. No grade was saved.';
    public const MSG_UNAVAILABLE = 'AI grading assistance is temporarily unavailable. Please try again later.';
    public const MSG_TIMEOUT = 'AI grading assistance took too long. Please try again.';

    public function __construct(
        protected AiService $aiService,
        protected AuditLogService $auditLogService,
        protected DocumentTextExtractor $extractor,
        protected StudentSubmissionService $submissionService,
    ) {}

    // ---------------------------------------------------------------- request

    /**
     * Queue an AI grading run for the answer. Idempotent: an active run is returned as-is.
     *
     * @return array{result: AiGradingResult, created: bool}
     * @throws AiGradingException
     */
    public function request(StudentAnswer $answer, User $user, bool $force = false): array
    {
        $answer->loadMissing(['question', 'submission']);
        $rubric = $this->resolveRubric($answer);
        $this->assertGradable($answer, $rubric);

        $active = AiGradingResult::where('student_answer_id', $answer->id)->active()->orderByDesc('id')->first();
        if ($active) {
            return ['result' => $active->load('criterionResults'), 'created' => false];
        }

        $current = AiGradingResult::where('student_answer_id', $answer->id)->current()->first();
        if ($current && !$force && $current->isCompleted() && empty($this->staleReasons($current, $answer))) {
            throw new AiGradingException(
                'An AI grading suggestion already exists for this answer. Use regenerate to request a new evaluation.',
                self::STATUS_CONFLICT
            );
        }

        $result = DB::transaction(function () use ($answer, $user, $rubric) {
            AiGradingResult::where('student_answer_id', $answer->id)->where('is_current', true)->update(['is_current' => false]);

            return AiGradingResult::create([
                'student_answer_id' => $answer->id,
                'student_submission_id' => $answer->student_submission_id,
                'question_id' => $answer->question_id,
                'rubric_id' => $rubric->id,
                'rubric_version' => $rubric->version,
                'answer_fingerprint' => $answer->contentFingerprint(),
                'context_fingerprint' => $this->contextFingerprint($answer->question, $rubric),
                'maximum_marks' => round((float) $rubric->total_marks, 2),
                'grading_status' => AiGradingResult::STATUS_PENDING,
                'is_current' => true,
                'requested_by' => $user->id,
            ]);
        });

        $this->auditLogService->log('AI_GRADING_REQUESTED', $result, $result->id, [
            'student_answer_id' => $answer->id,
            'submission_id' => $answer->student_submission_id,
            'question_id' => $answer->question_id,
            'rubric_id' => $rubric->id,
            'rubric_version' => $rubric->version,
            'regenerate' => $force,
        ], $user);

        GradeAnswerJob::dispatch($result->id, $user->id);

        return ['result' => $result, 'created' => true];
    }

    /**
     * Create a new grading run for the answer of an existing result (old runs are preserved).
     *
     * @throws AiGradingException
     */
    public function regenerate(AiGradingResult $result, User $user): AiGradingResult
    {
        $answer = $result->studentAnswer;
        if (!$answer) {
            throw new AiGradingException('The student answer for this result no longer exists.', 404);
        }

        $outcome = $this->request($answer, $user, true);
        if ($outcome['created']) {
            $this->auditLogService->log('AI_GRADING_REGENERATED', $outcome['result'], $outcome['result']->id, [
                'previous_result_id' => $result->id,
                'student_answer_id' => $answer->id,
            ], $user);
        }

        return $outcome['result'];
    }

    // ---------------------------------------------------------------- process

    /**
     * Run the AI evaluation for a PENDING result. Called from the queue job.
     */
    public function process(AiGradingResult $result): AiGradingResult
    {
        if ($result->grading_status !== AiGradingResult::STATUS_PENDING) {
            return $result;
        }

        $result->update(['grading_status' => AiGradingResult::STATUS_PROCESSING, 'error_message' => null]);

        $answer = $result->studentAnswer()->with(['question.assessment.course', 'question.learningOutcome', 'submission'])->first();
        $rubric = $result->rubric_id ? Rubric::with('criteria')->find($result->rubric_id) : null;

        try {
            if (!$answer || !$answer->question) {
                throw new AiGradingException('The student answer or its question no longer exists.', 404);
            }
            if (!$rubric || $rubric->criteria->isEmpty()) {
                throw new AiGradingException(self::MSG_NO_RUBRIC, self::STATUS_VALIDATION);
            }

            $text = $this->resolveAnswerText($answer);
            $payload = $this->buildPayload($answer, $rubric, $text);

            try {
                $aiResponse = $this->aiService->gradeAnswer($payload);
            } catch (Exception $e) {
                throw $this->translateAiException($e);
            }

            $normalized = $this->validateAiResponse($aiResponse, $rubric);

            DB::transaction(function () use ($result, $normalized, $rubric) {
                $result->criterionResults()->delete();
                foreach ($normalized['criterion_results'] as $idx => $c) {
                    AiGradingCriterionResult::create([
                        'ai_grading_result_id' => $result->id,
                        'rubric_criterion_id' => $c['rubric_criterion_id'],
                        'criterion' => $c['criterion'],
                        'suggested_marks' => $c['suggested_marks'],
                        'maximum_marks' => $c['maximum_marks'],
                        'evaluation' => $c['evaluation'],
                        'evidence' => $c['evidence'],
                        'missing_elements' => $c['missing_elements'],
                        'coverage_level' => $c['coverage_level'],
                        'sort_order' => $idx + 1,
                    ]);
                }

                $result->update([
                    'suggested_marks' => $normalized['suggested_marks'],
                    'maximum_marks' => round((float) $rubric->total_marks, 2),
                    'overall_feedback' => $normalized['overall_feedback'],
                    'strengths' => $normalized['strengths'],
                    'missing_elements' => $normalized['missing_elements'],
                    'evaluation_summary' => $normalized['evaluation_summary'],
                    'model_name' => $normalized['model_name'],
                    'model_version' => $normalized['model_version'],
                    'generation_method' => $normalized['generation_method'],
                    'grading_status' => AiGradingResult::STATUS_COMPLETED,
                    'error_message' => null,
                    'generated_at' => now(),
                ]);

                $submission = $result->submission;
                if ($submission && in_array($submission->grading_status, [StudentSubmission::GRADING_NOT_STARTED, StudentSubmission::GRADING_IN_PROGRESS], true)) {
                    $submission->update(['grading_status' => StudentSubmission::GRADING_AI_ASSISTED]);
                }
            });

            $this->auditLogService->log('AI_GRADING_COMPLETED', $result, $result->id, [
                'student_answer_id' => $result->student_answer_id,
                'suggested_marks' => $normalized['suggested_marks'],
                'maximum_marks' => round((float) $rubric->total_marks, 2),
                'criteria_count' => count($normalized['criterion_results']),
                'model_name' => $normalized['model_name'],
                'generation_method' => $normalized['generation_method'],
            ], $result->requester);
        } catch (AiGradingException $e) {
            $this->markFailed($result, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('AI grading failed for result ' . $result->id . ': ' . get_class($e));
            $this->markFailed($result, self::MSG_INVALID_AI);
        }

        return $result->fresh(['criterionResults']);
    }

    public function markFailed(AiGradingResult $result, string $message): void
    {
        $result->update([
            'grading_status' => AiGradingResult::STATUS_FAILED,
            'error_message' => Str::limit($message, 500, ''),
        ]);

        $this->auditLogService->log('AI_GRADING_FAILED', $result, $result->id, [
            'student_answer_id' => $result->student_answer_id,
            'reason' => Str::limit($message, 250, ''),
        ], $result->requester);
    }

    // ----------------------------------------------------------- faculty review

    /**
     * Record the faculty's final marks and feedback. AI suggested marks are never overwritten.
     *
     * @param array{final_marks: mixed, faculty_feedback?: ?string, decision?: ?string} $data
     * @throws AiGradingException
     */
    public function finalizeGrade(StudentAnswer $answer, User $user, array $data): StudentAnswer
    {
        $answer->loadMissing(['question', 'submission']);
        if (!$answer->question) {
            throw new AiGradingException('The question for this answer no longer exists.', 404);
        }
        if ($answer->submission && $answer->submission->status === StudentSubmission::STATUS_RETURNED) {
            throw new AiGradingException('Returned submissions are read-only.', self::STATUS_VALIDATION);
        }

        $marks = $this->validateFinalMarks($data['final_marks'] ?? null, $answer->question);
        $feedback = array_key_exists('faculty_feedback', $data) && $data['faculty_feedback'] !== null
            ? Str::limit(trim((string) $data['faculty_feedback']), 5000, '')
            : null;

        $aiResult = AiGradingResult::where('student_answer_id', $answer->id)->current()->first();
        $decision = null;
        if ($aiResult && $aiResult->isCompleted()) {
            $requested = isset($data['decision']) ? strtoupper((string) $data['decision']) : null;
            if ($requested !== null && !in_array($requested, AiGradingResult::DECISIONS, true)) {
                throw new AiGradingException('Unknown AI suggestion decision.', self::STATUS_VALIDATION);
            }
            $decision = $requested ?? (
                $aiResult->suggested_marks !== null && abs((float) $aiResult->suggested_marks - $marks) <= self::MARK_TOLERANCE
                    ? AiGradingResult::DECISION_ACCEPTED
                    : AiGradingResult::DECISION_MODIFIED
            );
        }

        DB::transaction(function () use ($answer, $user, $marks, $feedback, $data, $aiResult, $decision) {
            $attributes = [
                'awarded_marks' => $marks,
                'answer_status' => StudentAnswer::STATUS_REVIEWED,
            ];
            if (array_key_exists('faculty_feedback', $data)) {
                $attributes['faculty_feedback'] = $feedback;
            }
            $answer->update($attributes);

            $submission = $answer->submission;
            if ($submission) {
                $this->submissionService->recalculateSubmission($submission);
                $submission->refresh();
                if (in_array($submission->grading_status, [
                    StudentSubmission::GRADING_NOT_STARTED,
                    StudentSubmission::GRADING_IN_PROGRESS,
                    StudentSubmission::GRADING_AI_ASSISTED,
                ], true)) {
                    $submission->update(['grading_status' => StudentSubmission::GRADING_FACULTY_REVIEWED]);
                }
            }

            if ($aiResult && $decision) {
                $aiResult->update([
                    'grading_status' => AiGradingResult::STATUS_FINALIZED,
                    'faculty_decision' => $decision,
                    'reviewed_at' => now(),
                    'reviewed_by' => $user->id,
                ]);

                $this->auditLogService->log('AI_SUGGESTION_' . $decision, $aiResult, $aiResult->id, [
                    'student_answer_id' => $answer->id,
                    'ai_suggested_marks' => $aiResult->suggested_marks !== null ? (float) $aiResult->suggested_marks : null,
                    'faculty_final_marks' => $marks,
                    'difference' => $aiResult->suggested_marks !== null ? round($marks - (float) $aiResult->suggested_marks, 2) : null,
                ], $user);
            }

            $this->auditLogService->log('GRADE_FINALIZED', $answer, $answer->id, [
                'submission_id' => $answer->student_submission_id,
                'question_id' => $answer->question_id,
                'faculty_final_marks' => $marks,
                'maximum_marks' => round((float) $answer->question->marks, 2),
                'ai_result_id' => $aiResult?->id,
                'ai_suggested_marks' => $aiResult && $aiResult->suggested_marks !== null ? (float) $aiResult->suggested_marks : null,
                'has_feedback' => $feedback !== null && $feedback !== '',
            ], $user);
        });

        return $answer->fresh(['question', 'currentAiGrading.criterionResults']);
    }

    /**
     * Faculty explicitly rejects the AI suggestion. Marks are untouched.
     */
    public function rejectSuggestion(AiGradingResult $result, User $user): AiGradingResult
    {
        if (!$result->isCompleted()) {
            throw new AiGradingException('Only completed AI suggestions can be rejected.', self::STATUS_VALIDATION);
        }
        if ($result->grading_status === AiGradingResult::STATUS_FINALIZED) {
            throw new AiGradingException('This AI suggestion has already been finalized by faculty.', self::STATUS_VALIDATION);
        }

        $result->update([
            'grading_status' => AiGradingResult::STATUS_REVIEWED,
            'faculty_decision' => AiGradingResult::DECISION_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ]);

        $this->auditLogService->log('AI_SUGGESTION_REJECTED', $result, $result->id, [
            'student_answer_id' => $result->student_answer_id,
            'ai_suggested_marks' => $result->suggested_marks !== null ? (float) $result->suggested_marks : null,
        ], $user);

        return $result->fresh(['criterionResults']);
    }

    // ------------------------------------------------------------ presentation

    public function present(AiGradingResult $result, ?StudentAnswer $answer = null): array
    {
        $answer ??= $result->studentAnswer;
        $result->loadMissing('criterionResults');
        $stale = $answer ? $this->staleReasons($result, $answer) : [];

        return [
            'id' => $result->id,
            'student_answer_id' => $result->student_answer_id,
            'student_submission_id' => $result->student_submission_id,
            'question_id' => $result->question_id,
            'rubric_id' => $result->rubric_id,
            'rubric_version' => $result->rubric_version,
            'suggested_marks' => $result->suggested_marks !== null ? (float) $result->suggested_marks : null,
            'maximum_marks' => (float) $result->maximum_marks,
            'overall_feedback' => $result->overall_feedback,
            'strengths' => $result->strengths ?? [],
            'missing_elements' => $result->missing_elements ?? [],
            'evaluation_summary' => $result->evaluation_summary,
            'grading_status' => $result->grading_status,
            'is_current' => (bool) $result->is_current,
            'is_stale' => count($stale) > 0,
            'stale_reasons' => $stale,
            'faculty_decision' => $result->faculty_decision,
            'error_message' => $result->error_message,
            'model_name' => $result->model_name,
            'model_version' => $result->model_version,
            'generation_method' => $result->generation_method,
            'generated_at' => $result->generated_at?->toISOString(),
            'reviewed_at' => $result->reviewed_at?->toISOString(),
            'reviewed_by' => $result->reviewed_by,
            'created_at' => $result->created_at?->toISOString(),
            'updated_at' => $result->updated_at?->toISOString(),
            'criterion_results' => $result->criterionResults->map(fn (AiGradingCriterionResult $c) => [
                'id' => $c->id,
                'rubric_criterion_id' => $c->rubric_criterion_id,
                'criterion' => $c->criterion,
                'suggested_marks' => (float) $c->suggested_marks,
                'maximum_marks' => (float) $c->maximum_marks,
                'evaluation' => $c->evaluation,
                'evidence' => $c->evidence ?? [],
                'missing_elements' => $c->missing_elements ?? [],
                'coverage_level' => $c->coverage_level,
                'sort_order' => $c->sort_order,
            ])->values()->all(),
        ];
    }

    /**
     * Reasons the result no longer reflects the current answer / rubric / question.
     *
     * @return string[]
     */
    public function staleReasons(AiGradingResult $result, StudentAnswer $answer): array
    {
        if (!$result->isCompleted()) {
            return [];
        }

        $reasons = [];
        if ($result->answer_fingerprint && $result->answer_fingerprint !== $answer->contentFingerprint()) {
            $reasons[] = 'The student answer was modified after this evaluation.';
        }

        $answer->loadMissing('question.approvedRubric');
        $question = $answer->question;
        $rubric = $question?->approvedRubric;

        if (!$rubric) {
            $reasons[] = 'The rubric used for this evaluation is no longer approved.';
        } elseif ((int) $rubric->id !== (int) $result->rubric_id || (int) $rubric->version !== (int) $result->rubric_version) {
            $reasons[] = 'The rubric was modified after this evaluation.';
        } elseif ($question && $result->context_fingerprint && $result->context_fingerprint !== $this->contextFingerprint($question, $rubric->load('criteria'))) {
            $reasons[] = 'The question or rubric was modified after this evaluation.';
        }

        return $reasons;
    }

    // --------------------------------------------------------------- payload

    /**
     * Trusted payload from database records only (never from the frontend).
     */
    public function buildPayload(StudentAnswer $answer, Rubric $rubric, string $answerText): array
    {
        $answer->loadMissing(['question.assessment.course', 'question.learningOutcome']);
        $question = $answer->question;
        $rubric->loadMissing('criteria');
        $course = $question->assessment?->course;

        $payload = [
            'student_answer' => [
                'id' => $answer->id,
                'text' => $answerText,
                'answer_type' => $answer->answer_type,
            ],
            'question' => [
                'id' => $question->id,
                'text' => $question->question_text,
                'total_marks' => round((float) $question->marks, 2),
                'question_type' => $question->question_type ?: ($question->ai_question_type ?: 'descriptive'),
                'difficulty_level' => $question->difficulty_level ?: $question->ai_difficulty_level,
                'cognitive_level' => $question->cognitive_level ?: $question->ai_cognitive_level,
                'expected_answer' => $question->expected_answer,
            ],
            'rubric' => [
                'id' => $rubric->id,
                'version' => $rubric->version,
                'total_marks' => round((float) $rubric->total_marks, 2),
                'general_guidance' => $rubric->general_guidance,
                'criteria' => $rubric->criteria->map(fn ($c) => [
                    'id' => $c->id,
                    'criterion' => $c->criterion,
                    'description' => $c->description,
                    'max_marks' => round((float) $c->max_marks, 2),
                    'scoring_guidance' => $c->scoring_guidance,
                    'expected_indicators' => $c->expected_indicators ?? [],
                    'sort_order' => $c->sort_order,
                ])->values()->all(),
            ],
        ];

        if ($question->learningOutcome) {
            $payload['learning_outcome'] = [
                'code' => $question->learningOutcome->code,
                'description' => $question->learningOutcome->description,
            ];
        }
        if ($course) {
            $payload['course_context'] = [
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
            ];
        }

        return $payload;
    }

    /**
     * Text to evaluate: typed answer text, or text extracted from a PDF/DOCX/TXT answer file.
     *
     * @throws AiGradingException
     */
    public function resolveAnswerText(StudentAnswer $answer): string
    {
        $text = trim((string) $answer->answer_text);
        if ($text !== '') {
            return $text;
        }

        if (!$answer->hasFile()) {
            throw new AiGradingException('This answer has no content to evaluate.', self::STATUS_VALIDATION);
        }

        $extension = strtolower(pathinfo((string) $answer->answer_file_name, PATHINFO_EXTENSION)
            ?: pathinfo((string) $answer->answer_file_path, PATHINFO_EXTENSION));

        if ($answer->answer_type === StudentAnswer::TYPE_IMAGE || in_array($extension, StudentSubmissionService::IMAGE_EXTENSIONS, true)) {
            throw new AiGradingException(self::MSG_IMAGE_UNSUPPORTED, self::STATUS_VALIDATION);
        }

        $disk = Storage::disk(StudentSubmissionService::DISK);
        if (!$disk->exists($answer->answer_file_path)) {
            throw new AiGradingException('The answer file is no longer available for AI grading.', self::STATUS_VALIDATION);
        }

        try {
            $extracted = $this->extractor->extract($disk->path($answer->answer_file_path), $extension);
        } catch (Exception $e) {
            Log::warning('AI grading text extraction failed for answer ' . $answer->id . ': ' . get_class($e));
            throw new AiGradingException('The answer file could not be read for AI grading. Add the answer text to continue.', self::STATUS_VALIDATION);
        }

        $extractedText = trim((string) ($extracted['cleaned_text'] ?: $extracted['raw_text'] ?? ''));
        if ($extractedText === '') {
            throw new AiGradingException('The answer file contains no readable text for AI grading.', self::STATUS_VALIDATION);
        }

        return $extractedText;
    }

    // ------------------------------------------------------------- validation

    /**
     * Validate and normalize the AI response against the rubric. Rejects any inconsistent result.
     *
     * @throws AiGradingException
     */
    public function validateAiResponse(array $response, Rubric $rubric): array
    {
        $rubric->loadMissing('criteria');
        $maximum = round((float) $rubric->total_marks, 2);

        $suggested = $response['suggested_marks'] ?? null;
        if (!is_numeric($suggested)) {
            throw $this->invalidAi('suggested_marks missing or not numeric');
        }
        $suggested = round((float) $suggested, 2);
        if ($suggested < 0 || $suggested > $maximum + self::MARK_TOLERANCE) {
            throw $this->invalidAi("suggested_marks {$suggested} outside 0..{$maximum}");
        }

        $responseMax = $response['maximum_marks'] ?? null;
        if (!is_numeric($responseMax) || abs((float) $responseMax - $maximum) > self::MARK_TOLERANCE) {
            throw $this->invalidAi('maximum_marks does not match the rubric total');
        }

        $criterionResults = $response['criterion_results'] ?? null;
        if (!is_array($criterionResults) || count($criterionResults) === 0) {
            throw $this->invalidAi('criterion_results missing or empty');
        }

        $expected = $rubric->criteria->keyBy('id');
        $seen = [];
        $normalized = [];
        $criterionTotal = 0.0;

        foreach ($criterionResults as $item) {
            if (!is_array($item)) {
                throw $this->invalidAi('criterion result is not an object');
            }
            $cid = $item['rubric_criterion_id'] ?? null;
            if (!is_numeric($cid) || !$expected->has((int) $cid)) {
                throw $this->invalidAi('criterion result references an unknown rubric criterion');
            }
            $cid = (int) $cid;
            if (isset($seen[$cid])) {
                throw $this->invalidAi("criterion {$cid} appears more than once");
            }
            $seen[$cid] = true;
            $criterion = $expected->get($cid);
            $criterionMax = round((float) $criterion->max_marks, 2);

            $marks = $item['suggested_marks'] ?? null;
            if (!is_numeric($marks)) {
                throw $this->invalidAi("criterion {$cid} suggested_marks not numeric");
            }
            $marks = round((float) $marks, 2);
            if ($marks < 0 || $marks > $criterionMax + self::MARK_TOLERANCE) {
                throw $this->invalidAi("criterion {$cid} suggested_marks {$marks} outside 0..{$criterionMax}");
            }
            $itemMax = $item['maximum_marks'] ?? null;
            if (!is_numeric($itemMax) || abs((float) $itemMax - $criterionMax) > self::MARK_TOLERANCE) {
                throw $this->invalidAi("criterion {$cid} maximum_marks does not match the rubric");
            }

            $evaluation = trim((string) ($item['evaluation'] ?? ''));
            if ($evaluation === '') {
                throw $this->invalidAi("criterion {$cid} evaluation is empty");
            }

            $criterionTotal += $marks;
            $normalized[] = [
                'rubric_criterion_id' => $cid,
                'criterion' => mb_substr((string) ($item['criterion'] ?? $criterion->criterion), 0, 255),
                'suggested_marks' => $marks,
                'maximum_marks' => $criterionMax,
                'evaluation' => mb_substr($evaluation, 0, 2000),
                'evidence' => $this->stringList($item['evidence'] ?? [], 8, 500),
                'missing_elements' => $this->stringList($item['missing_elements'] ?? [], 8, 500),
                'coverage_level' => isset($item['coverage_level']) ? mb_substr(strtoupper((string) $item['coverage_level']), 0, 20) : null,
                'sort_order' => (int) ($criterion->sort_order ?? 0),
            ];
        }

        if (count($seen) !== $expected->count()) {
            throw $this->invalidAi('criterion results do not cover every rubric criterion');
        }
        if (abs(round($criterionTotal, 2) - $suggested) > self::MARK_TOLERANCE) {
            throw $this->invalidAi("criterion total {$criterionTotal} does not equal suggested_marks {$suggested}");
        }

        usort($normalized, fn ($a, $b) => [$a['sort_order'], $a['rubric_criterion_id']] <=> [$b['sort_order'], $b['rubric_criterion_id']]);

        $feedback = trim((string) ($response['overall_feedback'] ?? ''));
        $summary = trim((string) ($response['evaluation_summary'] ?? ''));
        if ($feedback === '' || $summary === '') {
            throw $this->invalidAi('overall_feedback or evaluation_summary is empty');
        }

        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $method = strtolower((string) ($metadata['generation_method'] ?? 'ai_assisted'));

        return [
            'suggested_marks' => $suggested,
            'maximum_marks' => $maximum,
            'criterion_results' => $normalized,
            'overall_feedback' => mb_substr($feedback, 0, 5000),
            'strengths' => $this->stringList($response['strengths'] ?? [], 10, 500),
            'missing_elements' => $this->stringList($response['missing_elements'] ?? [], 12, 500),
            'evaluation_summary' => mb_substr($summary, 0, 5000),
            'model_name' => isset($metadata['model']) ? mb_substr((string) $metadata['model'], 0, 255) : null,
            'model_version' => isset($metadata['version']) ? mb_substr((string) $metadata['version'], 0, 50) : null,
            'generation_method' => mb_substr($method, 0, 40),
        ];
    }

    /**
     * Final marks must satisfy 0 <= marks <= question.marks (two-decimal precision).
     *
     * @throws AiGradingException
     */
    public function validateFinalMarks(mixed $marks, Question $question): float
    {
        if ($marks === null || $marks === '' || !is_numeric($marks)) {
            throw new AiGradingException('Final marks are required and must be a number.', self::STATUS_VALIDATION);
        }
        $value = round((float) $marks, 2);
        $max = round((float) $question->marks, 2);
        if ($value < 0 || $value > $max) {
            throw new AiGradingException("Final marks must be between 0 and {$max} for this question.", self::STATUS_VALIDATION);
        }

        return $value;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @throws AiGradingException
     */
    protected function resolveRubric(StudentAnswer $answer): Rubric
    {
        $question = $answer->question;
        if (!$question) {
            throw new AiGradingException('The question for this answer no longer exists.', 404);
        }

        $rubric = Rubric::with('criteria')->where('question_id', $question->id)->approved()->orderByDesc('version')->first();
        if (!$rubric || $rubric->criteria->isEmpty()) {
            throw new AiGradingException(self::MSG_NO_RUBRIC, self::STATUS_VALIDATION);
        }

        return $rubric;
    }

    /**
     * @throws AiGradingException
     */
    protected function assertGradable(StudentAnswer $answer, Rubric $rubric): void
    {
        $question = $answer->question;
        $marks = round((float) $question->marks, 2);
        if ($marks <= 0) {
            throw new AiGradingException('This question has no marks allocated. Set the question marks before requesting AI grading.', self::STATUS_VALIDATION);
        }
        if (abs(round((float) $rubric->total_marks, 2) - $marks) > self::MARK_TOLERANCE) {
            throw new AiGradingException('The approved rubric total does not match the question marks. Update the rubric before requesting AI grading.', self::STATUS_VALIDATION);
        }

        $hasText = trim((string) $answer->answer_text) !== '';
        if (!$hasText && !$answer->hasFile()) {
            throw new AiGradingException('This answer has no content to evaluate.', self::STATUS_VALIDATION);
        }
        if (!$hasText && $answer->answer_type === StudentAnswer::TYPE_IMAGE) {
            throw new AiGradingException(self::MSG_IMAGE_UNSUPPORTED, self::STATUS_VALIDATION);
        }
        if (!$hasText && $answer->hasFile()) {
            $extension = strtolower(pathinfo((string) $answer->answer_file_name, PATHINFO_EXTENSION));
            if (in_array($extension, StudentSubmissionService::IMAGE_EXTENSIONS, true)) {
                throw new AiGradingException(self::MSG_IMAGE_UNSUPPORTED, self::STATUS_VALIDATION);
            }
        }
    }

    public function contextFingerprint(Question $question, Rubric $rubric): string
    {
        $rubric->loadMissing('criteria');
        $parts = [
            (string) $question->question_text,
            (string) round((float) $question->marks, 2),
            (string) $rubric->id,
            (string) $rubric->version,
        ];
        foreach ($rubric->criteria as $c) {
            $parts[] = implode(':', [
                $c->id,
                $c->criterion,
                (string) round((float) $c->max_marks, 2),
                $c->description,
                json_encode($c->expected_indicators ?? []),
            ]);
        }

        return hash('sha256', implode('|', $parts));
    }

    protected function stringList(mixed $value, int $max, int $length): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $text = trim($item);
            if ($text !== '') {
                $out[] = mb_substr($text, 0, $length);
            }
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    protected function invalidAi(string $reason): AiGradingException
    {
        Log::warning('AI grading result rejected: ' . $reason);

        return new AiGradingException(self::MSG_INVALID_AI, self::STATUS_AI_INVALID);
    }

    protected function translateAiException(Exception $e): AiGradingException
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return new AiGradingException(self::MSG_TIMEOUT, self::STATUS_AI_TIMEOUT);
        }
        if (str_contains($msg, 'unavailable') || str_contains($msg, 'unreachable')) {
            return new AiGradingException(self::MSG_UNAVAILABLE, self::STATUS_AI_UNAVAILABLE);
        }
        if (str_contains($msg, 'validation') || str_contains($msg, 'cannot be empty') || str_contains($msg, 'greater than zero') || str_contains($msg, 'required')) {
            return new AiGradingException($e->getMessage(), self::STATUS_VALIDATION);
        }

        return new AiGradingException(self::MSG_INVALID_AI, self::STATUS_AI_INVALID);
    }
}
