<?php

namespace App\Services;

use App\Jobs\AnalyzeAnswerRubricAlignmentJob;
use App\Models\AnswerRubricAlignment;
use App\Models\AnswerRubricCriterionAlignment;
use App\Models\Rubric;
use App\Models\StudentAnswer;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * STEP 28: Answer <-> Rubric Alignment orchestration.
 *
 * Alignment answers "how well does the answer satisfy each rubric criterion?". It is not a
 * grade and not correctness. This service never touches student_answers.awarded_marks,
 * faculty_feedback, rubrics or answers. AI output is re-validated before it is stored.
 */
class RubricAlignmentService
{
    public const SCORE_TOLERANCE = 0.05;
    public const MARK_TOLERANCE = 0.005;

    public const STATUS_AI_UNAVAILABLE = 503;
    public const STATUS_AI_TIMEOUT = 504;
    public const STATUS_AI_INVALID = 502;
    public const STATUS_VALIDATION = 422;
    public const STATUS_CONFLICT = 409;

    public const MSG_NO_RUBRIC = 'No approved rubric is available for this question. Create or approve a rubric before analyzing rubric alignment.';
    public const MSG_INVALID_AI = 'FacultyLens could not validate the AI alignment result. Nothing was saved.';
    public const MSG_UNAVAILABLE = 'Rubric alignment analysis is temporarily unavailable. Please try again later.';
    public const MSG_TIMEOUT = 'Rubric alignment analysis took too long. Please try again.';

    public function __construct(
        protected AiService $aiService,
        protected AuditLogService $auditLogService,
        protected AiGradingService $gradingService,
    ) {}

    // ---------------------------------------------------------------- request

    /**
     * Queue an alignment analysis. Idempotent: an active run for the same answer is returned as-is.
     *
     * @return array{alignment: AnswerRubricAlignment, created: bool}
     * @throws RubricAlignmentException
     */
    public function request(StudentAnswer $answer, User $user, bool $force = false): array
    {
        $answer->loadMissing(['question', 'submission']);
        $rubric = $this->resolveRubric($answer);
        $this->assertAnalyzable($answer, $rubric);

        $active = AnswerRubricAlignment::where('student_answer_id', $answer->id)->active()->orderByDesc('id')->first();
        if ($active) {
            return ['alignment' => $active->load('criterionAlignments'), 'created' => false];
        }

        $current = AnswerRubricAlignment::where('student_answer_id', $answer->id)->current()->first();
        if ($current && !$force && $current->isCompleted() && empty($this->staleReasons($current, $answer))) {
            throw new RubricAlignmentException(
                'A rubric alignment analysis already exists for this answer. Use regenerate to run a new analysis.',
                self::STATUS_CONFLICT
            );
        }

        $alignment = DB::transaction(function () use ($answer, $user, $rubric) {
            AnswerRubricAlignment::where('student_answer_id', $answer->id)->where('is_current', true)->update(['is_current' => false]);

            return AnswerRubricAlignment::create([
                'student_answer_id' => $answer->id,
                'student_submission_id' => $answer->student_submission_id,
                'question_id' => $answer->question_id,
                'rubric_id' => $rubric->id,
                'rubric_version' => $rubric->version,
                'answer_fingerprint' => $answer->contentFingerprint(),
                'context_fingerprint' => $this->gradingService->contextFingerprint($answer->question, $rubric),
                'analysis_status' => AnswerRubricAlignment::STATUS_PENDING,
                'is_current' => true,
                'requested_by' => $user->id,
            ]);
        });

        $this->auditLogService->log('RUBRIC_ALIGNMENT_REQUESTED', $alignment, $alignment->id, [
            'student_answer_id' => $answer->id,
            'submission_id' => $answer->student_submission_id,
            'question_id' => $answer->question_id,
            'rubric_id' => $rubric->id,
            'rubric_version' => $rubric->version,
            'regenerate' => $force,
        ], $user);

        AnalyzeAnswerRubricAlignmentJob::dispatch($alignment->id, $user->id);

        return ['alignment' => $alignment, 'created' => true];
    }

    /**
     * @throws RubricAlignmentException
     */
    public function regenerate(AnswerRubricAlignment $alignment, User $user): AnswerRubricAlignment
    {
        $answer = $alignment->studentAnswer;
        if (!$answer) {
            throw new RubricAlignmentException('The student answer for this analysis no longer exists.', 404);
        }

        $outcome = $this->request($answer, $user, true);
        if ($outcome['created']) {
            $this->auditLogService->log('RUBRIC_ALIGNMENT_REGENERATED', $outcome['alignment'], $outcome['alignment']->id, [
                'previous_alignment_id' => $alignment->id,
                'student_answer_id' => $answer->id,
            ], $user);
        }

        return $outcome['alignment'];
    }

    // ---------------------------------------------------------------- process

    /**
     * Run the AI analysis for a PENDING record. Called from the queue job.
     */
    public function process(AnswerRubricAlignment $alignment): AnswerRubricAlignment
    {
        if ($alignment->analysis_status !== AnswerRubricAlignment::STATUS_PENDING) {
            return $alignment;
        }

        $alignment->update(['analysis_status' => AnswerRubricAlignment::STATUS_PROCESSING, 'error_message' => null]);

        $answer = $alignment->studentAnswer()->with(['question.assessment.course', 'question.learningOutcome', 'submission'])->first();
        $rubric = $alignment->rubric_id ? Rubric::with('criteria')->find($alignment->rubric_id) : null;

        try {
            if (!$answer || !$answer->question) {
                throw new RubricAlignmentException('The student answer or its question no longer exists.', 404);
            }
            if (!$rubric || $rubric->criteria->isEmpty()) {
                throw new RubricAlignmentException(self::MSG_NO_RUBRIC, self::STATUS_VALIDATION);
            }

            try {
                $text = $this->gradingService->resolveAnswerText($answer);
            } catch (AiGradingException $e) {
                throw new RubricAlignmentException($e->getMessage(), $e->getStatus());
            }

            $payload = $this->gradingService->buildPayload($answer, $rubric, $text);

            try {
                $aiResponse = $this->aiService->analyzeAnswerRubricAlignment($payload);
            } catch (Exception $e) {
                throw $this->translateAiException($e);
            }

            $normalized = $this->validateAiResponse($aiResponse, $rubric);

            DB::transaction(function () use ($alignment, $normalized) {
                $alignment->criterionAlignments()->delete();
                foreach ($normalized['criterion_alignments'] as $idx => $c) {
                    AnswerRubricCriterionAlignment::create([
                        'answer_rubric_alignment_id' => $alignment->id,
                        'rubric_criterion_id' => $c['rubric_criterion_id'],
                        'criterion' => $c['criterion'],
                        'max_marks' => $c['max_marks'],
                        'alignment_score' => $c['alignment_score'],
                        'similarity' => $c['similarity'],
                        'alignment_status' => $c['alignment_status'],
                        'evidence' => $c['evidence'],
                        'missing_elements' => $c['missing_elements'],
                        'explanation' => $c['explanation'],
                        'sort_order' => $idx + 1,
                    ]);
                }

                $alignment->update([
                    'overall_alignment_score' => $normalized['overall_alignment_score'],
                    'unweighted_alignment_score' => $normalized['unweighted_alignment_score'],
                    'alignment_status' => $normalized['overall_alignment_status'],
                    'counts' => $normalized['counts'],
                    'summary' => $normalized['summary'],
                    'strengths' => $normalized['strengths'],
                    'missing_elements' => $normalized['missing_elements'],
                    'model_name' => $normalized['model_name'],
                    'model_version' => $normalized['model_version'],
                    'analysis_method' => $normalized['analysis_method'],
                    'thresholds' => $normalized['thresholds'],
                    'analysis_status' => AnswerRubricAlignment::STATUS_COMPLETED,
                    'error_message' => null,
                    'generated_at' => now(),
                ]);
            });

            $this->auditLogService->log('RUBRIC_ALIGNMENT_COMPLETED', $alignment, $alignment->id, [
                'student_answer_id' => $alignment->student_answer_id,
                'overall_alignment_score' => $normalized['overall_alignment_score'],
                'alignment_status' => $normalized['overall_alignment_status'],
                'counts' => $normalized['counts'],
                'rubric_version' => $alignment->rubric_version,
                'model_name' => $normalized['model_name'],
                'model_version' => $normalized['model_version'],
                'analysis_method' => $normalized['analysis_method'],
            ], $alignment->requester);
        } catch (RubricAlignmentException $e) {
            $this->markFailed($alignment, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Rubric alignment failed for alignment ' . $alignment->id . ': ' . get_class($e));
            $this->markFailed($alignment, self::MSG_INVALID_AI);
        }

        return $alignment->fresh(['criterionAlignments']);
    }

    public function markFailed(AnswerRubricAlignment $alignment, string $message): void
    {
        $alignment->update([
            'analysis_status' => AnswerRubricAlignment::STATUS_FAILED,
            'error_message' => Str::limit($message, 500, ''),
        ]);

        $this->auditLogService->log('RUBRIC_ALIGNMENT_FAILED', $alignment, $alignment->id, [
            'student_answer_id' => $alignment->student_answer_id,
            'reason' => Str::limit($message, 250, ''),
        ], $alignment->requester);
    }

    // ----------------------------------------------------------------- review

    /**
     * Faculty marks the analysis as reviewed. Grades are never touched here.
     *
     * @throws RubricAlignmentException
     */
    public function markReviewed(AnswerRubricAlignment $alignment, User $user): AnswerRubricAlignment
    {
        if (!$alignment->isCompleted()) {
            throw new RubricAlignmentException('Only completed alignment analyses can be marked as reviewed.', self::STATUS_VALIDATION);
        }

        $alignment->update([
            'analysis_status' => AnswerRubricAlignment::STATUS_REVIEWED,
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
        ]);

        $this->auditLogService->log('RUBRIC_ALIGNMENT_REVIEWED', $alignment, $alignment->id, [
            'student_answer_id' => $alignment->student_answer_id,
            'overall_alignment_score' => $alignment->overall_alignment_score !== null ? (float) $alignment->overall_alignment_score : null,
        ], $user);

        return $alignment->fresh(['criterionAlignments']);
    }

    // ------------------------------------------------------------ presentation

    public function present(AnswerRubricAlignment $alignment, ?StudentAnswer $answer = null): array
    {
        $answer ??= $alignment->studentAnswer;
        $alignment->loadMissing('criterionAlignments');
        $stale = $answer ? $this->staleReasons($alignment, $answer) : [];

        return [
            'id' => $alignment->id,
            'student_answer_id' => $alignment->student_answer_id,
            'student_submission_id' => $alignment->student_submission_id,
            'question_id' => $alignment->question_id,
            'rubric_id' => $alignment->rubric_id,
            'rubric_version' => $alignment->rubric_version,
            'overall_alignment_score' => $alignment->overall_alignment_score !== null ? (float) $alignment->overall_alignment_score : null,
            'unweighted_alignment_score' => $alignment->unweighted_alignment_score !== null ? (float) $alignment->unweighted_alignment_score : null,
            'alignment_status' => $alignment->alignment_status,
            'analysis_status' => $alignment->analysis_status,
            'is_current' => (bool) $alignment->is_current,
            'is_stale' => count($stale) > 0,
            'stale_reasons' => $stale,
            'counts' => $alignment->counts ?? ['strong' => 0, 'partial' => 0, 'weak' => 0, 'not_aligned' => 0],
            'summary' => $alignment->summary,
            'strengths' => $alignment->strengths ?? [],
            'missing_elements' => $alignment->missing_elements ?? [],
            'error_message' => $alignment->error_message,
            'model_name' => $alignment->model_name,
            'model_version' => $alignment->model_version,
            'analysis_method' => $alignment->analysis_method,
            'thresholds' => $alignment->thresholds,
            'generated_at' => $alignment->generated_at?->toISOString(),
            'reviewed_at' => $alignment->reviewed_at?->toISOString(),
            'reviewed_by' => $alignment->reviewed_by,
            'created_at' => $alignment->created_at?->toISOString(),
            'updated_at' => $alignment->updated_at?->toISOString(),
            'criterion_alignments' => $alignment->criterionAlignments->map(fn (AnswerRubricCriterionAlignment $c) => [
                'id' => $c->id,
                'rubric_criterion_id' => $c->rubric_criterion_id,
                'criterion' => $c->criterion,
                'max_marks' => (float) $c->max_marks,
                'alignment_score' => (float) $c->alignment_score,
                'similarity' => $c->similarity !== null ? (float) $c->similarity : null,
                'alignment_status' => $c->alignment_status,
                'evidence' => $c->evidence ?? [],
                'missing_elements' => $c->missing_elements ?? [],
                'explanation' => $c->explanation,
                'sort_order' => $c->sort_order,
            ])->values()->all(),
        ];
    }

    /**
     * @return string[]
     */
    public function staleReasons(AnswerRubricAlignment $alignment, StudentAnswer $answer): array
    {
        if (!$alignment->isCompleted()) {
            return [];
        }

        $reasons = [];
        if ($alignment->answer_fingerprint && $alignment->answer_fingerprint !== $answer->contentFingerprint()) {
            $reasons[] = 'The student answer was modified after this analysis.';
        }

        $answer->loadMissing('question.approvedRubric');
        $question = $answer->question;
        $rubric = $question?->approvedRubric;

        if (!$rubric) {
            $reasons[] = 'The rubric used for this analysis is no longer approved.';
        } elseif ((int) $rubric->id !== (int) $alignment->rubric_id || (int) $rubric->version !== (int) $alignment->rubric_version) {
            $reasons[] = 'The rubric was modified after this analysis.';
        } elseif ($question && $alignment->context_fingerprint && $alignment->context_fingerprint !== $this->gradingService->contextFingerprint($question, $rubric->load('criteria'))) {
            $reasons[] = 'The question or rubric was modified after this analysis.';
        }

        return $reasons;
    }

    // ------------------------------------------------------------- validation

    /**
     * Validate and normalize the AI response against the rubric. Scores are recomputed, not trusted.
     *
     * @throws RubricAlignmentException
     */
    public function validateAiResponse(array $response, Rubric $rubric): array
    {
        $rubric->loadMissing('criteria');

        $items = $response['criterion_alignments'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            throw $this->invalidAi('criterion_alignments missing or empty');
        }

        $expected = $rubric->criteria->keyBy('id');
        $seen = [];
        $normalized = [];
        $weightedNum = 0.0;
        $unweightedSum = 0.0;
        $totalMarks = 0.0;
        $counts = ['strong' => 0, 'partial' => 0, 'weak' => 0, 'not_aligned' => 0];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw $this->invalidAi('criterion alignment is not an object');
            }
            $cid = $item['rubric_criterion_id'] ?? null;
            if (!is_numeric($cid) || !$expected->has((int) $cid)) {
                throw $this->invalidAi('criterion alignment references an unknown rubric criterion');
            }
            $cid = (int) $cid;
            if (isset($seen[$cid])) {
                throw $this->invalidAi("criterion {$cid} appears more than once");
            }
            $seen[$cid] = true;
            $criterion = $expected->get($cid);
            $maxMarks = round((float) $criterion->max_marks, 2);

            $status = strtoupper((string) ($item['alignment_status'] ?? ''));
            if (!in_array($status, AnswerRubricAlignment::ALIGNMENT_STATUSES, true)) {
                throw $this->invalidAi("criterion {$cid} has an unknown alignment status");
            }
            $weight = AnswerRubricAlignment::ALIGNMENT_WEIGHTS[$status];
            $score = $item['alignment_score'] ?? null;
            if (!is_numeric($score) || abs((float) $score - $weight) > 0.000001) {
                throw $this->invalidAi("criterion {$cid} alignment_score does not match its status weight");
            }

            $explanation = trim((string) ($item['explanation'] ?? ''));
            if ($explanation === '') {
                throw $this->invalidAi("criterion {$cid} explanation is empty");
            }

            $similarity = $item['similarity'] ?? null;
            if ($similarity !== null && (!is_numeric($similarity) || $similarity < 0 || $similarity > 1)) {
                throw $this->invalidAi("criterion {$cid} similarity outside 0..1");
            }

            $weightedNum += $weight * $maxMarks;
            $unweightedSum += $weight;
            $totalMarks += $maxMarks;
            $counts[strtolower($status)]++;

            $normalized[] = [
                'rubric_criterion_id' => $cid,
                'criterion' => mb_substr((string) ($item['criterion'] ?? $criterion->criterion), 0, 255),
                'max_marks' => $maxMarks,
                'alignment_score' => $weight,
                'similarity' => $similarity !== null ? round((float) $similarity, 4) : null,
                'alignment_status' => $status,
                'evidence' => $this->stringList($item['evidence'] ?? [], 6, 500),
                'missing_elements' => $this->stringList($item['missing_elements'] ?? [], 8, 500),
                'explanation' => mb_substr($explanation, 0, 2000),
                'sort_order' => (int) ($criterion->sort_order ?? 0),
            ];
        }

        if (count($seen) !== $expected->count()) {
            throw $this->invalidAi('criterion alignments do not cover every rubric criterion');
        }

        $weighted = $totalMarks > 0 ? round($weightedNum / $totalMarks * 100, 2) : 0.0;
        $unweighted = round($unweightedSum / count($normalized) * 100, 2);

        $reportedScore = $response['overall_alignment_score'] ?? null;
        if (!is_numeric($reportedScore) || abs((float) $reportedScore - $weighted) > self::SCORE_TOLERANCE) {
            throw $this->invalidAi("overall_alignment_score does not match the mark-weighted criterion scores ({$weighted})");
        }
        $reportedUnweighted = $response['unweighted_alignment_score'] ?? $unweighted;
        if (!is_numeric($reportedUnweighted) || abs((float) $reportedUnweighted - $unweighted) > self::SCORE_TOLERANCE) {
            throw $this->invalidAi('unweighted_alignment_score does not match the criterion scores');
        }

        $overallStatus = strtoupper((string) ($response['overall_alignment_status'] ?? ''));
        if (!in_array($overallStatus, AnswerRubricAlignment::ALIGNMENT_STATUSES, true)) {
            throw $this->invalidAi('overall_alignment_status is unknown');
        }

        usort($normalized, fn ($a, $b) => [$a['sort_order'], $a['rubric_criterion_id']] <=> [$b['sort_order'], $b['rubric_criterion_id']]);

        $summary = trim((string) ($response['summary'] ?? ''));
        if ($summary === '') {
            $summary = "Mark-weighted alignment {$weighted}% across " . count($normalized) . ' rubric criteria.';
        }

        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $thresholds = is_array($metadata['thresholds'] ?? null) ? array_map('floatval', array_filter($metadata['thresholds'], 'is_numeric')) : null;

        return [
            'overall_alignment_score' => $weighted,
            'unweighted_alignment_score' => $unweighted,
            'overall_alignment_status' => $overallStatus,
            'counts' => $counts,
            'summary' => mb_substr($summary, 0, 2000),
            'strengths' => $this->stringList($response['strengths'] ?? [], 10, 500),
            'missing_elements' => $this->stringList($response['missing_elements'] ?? [], 12, 500),
            'criterion_alignments' => $normalized,
            'model_name' => isset($metadata['model']) ? mb_substr((string) $metadata['model'], 0, 255) : null,
            'model_version' => isset($metadata['version']) ? mb_substr((string) $metadata['version'], 0, 50) : null,
            'analysis_method' => mb_substr(strtolower((string) ($metadata['method'] ?? 'semantic_and_rubric_alignment')), 0, 40),
            'thresholds' => $thresholds ?: null,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @throws RubricAlignmentException
     */
    protected function resolveRubric(StudentAnswer $answer): Rubric
    {
        $question = $answer->question;
        if (!$question) {
            throw new RubricAlignmentException('The question for this answer no longer exists.', 404);
        }

        $rubric = Rubric::with('criteria')->where('question_id', $question->id)->approved()->orderByDesc('version')->first();
        if (!$rubric || $rubric->criteria->isEmpty()) {
            throw new RubricAlignmentException(self::MSG_NO_RUBRIC, self::STATUS_VALIDATION);
        }

        return $rubric;
    }

    /**
     * @throws RubricAlignmentException
     */
    protected function assertAnalyzable(StudentAnswer $answer, Rubric $rubric): void
    {
        $hasText = trim((string) $answer->answer_text) !== '';
        if (!$hasText && !$answer->hasFile()) {
            throw new RubricAlignmentException('This answer has no content to analyze.', self::STATUS_VALIDATION);
        }
        if (!$hasText) {
            $extension = strtolower(pathinfo((string) $answer->answer_file_name, PATHINFO_EXTENSION));
            if ($answer->answer_type === StudentAnswer::TYPE_IMAGE || in_array($extension, StudentSubmissionService::IMAGE_EXTENSIONS, true)) {
                throw new RubricAlignmentException(AiGradingService::MSG_IMAGE_UNSUPPORTED, self::STATUS_VALIDATION);
            }
        }
        if ($rubric->criteria->sum(fn ($c) => (float) $c->max_marks) <= 0) {
            throw new RubricAlignmentException('The approved rubric has no marks allocated to its criteria.', self::STATUS_VALIDATION);
        }
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

    protected function invalidAi(string $reason): RubricAlignmentException
    {
        Log::warning('AI alignment result rejected: ' . $reason);

        return new RubricAlignmentException(self::MSG_INVALID_AI, self::STATUS_AI_INVALID);
    }

    protected function translateAiException(Exception $e): RubricAlignmentException
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return new RubricAlignmentException(self::MSG_TIMEOUT, self::STATUS_AI_TIMEOUT);
        }
        if (str_contains($msg, 'unavailable') || str_contains($msg, 'unreachable')) {
            return new RubricAlignmentException(self::MSG_UNAVAILABLE, self::STATUS_AI_UNAVAILABLE);
        }
        if (str_contains($msg, 'validation') || str_contains($msg, 'cannot be empty') || str_contains($msg, 'required')) {
            return new RubricAlignmentException($e->getMessage(), self::STATUS_VALIDATION);
        }

        return new RubricAlignmentException(self::MSG_INVALID_AI, self::STATUS_AI_INVALID);
    }
}
