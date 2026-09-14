<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STEP 25: Orchestrates AI rubric generation, validation, persistence, editing,
 * approval and versioning. AI output is never trusted without validation.
 */
class RubricService
{
    public const MARK_TOLERANCE = 0.005;
    public const MAX_CRITERIA = 12;

    public const STATUS_AI_UNAVAILABLE = 503;
    public const STATUS_AI_TIMEOUT = 504;
    public const STATUS_AI_INVALID = 502;
    public const STATUS_VALIDATION = 422;

    public function __construct(
        protected AiService $aiService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Build a trusted generation payload from database records (never from the frontend).
     */
    public function buildGenerationPayload(Question $question): array
    {
        $question->loadMissing(['assessment.course', 'learningOutcome']);
        $course = $question->assessment?->course;

        $payload = [
            'question_id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type ?: ($question->ai_question_type ?: 'descriptive'),
            'total_marks' => (float) $question->marks,
            'difficulty_level' => $question->difficulty_level ?: $question->ai_difficulty_level,
            'cognitive_level' => $question->cognitive_level ?: $question->ai_cognitive_level,
            'expected_answer' => $question->expected_answer,
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
     * Generate a new DRAFT rubric version for the question. Existing versions are preserved.
     *
     * @throws RubricException
     */
    public function generate(Question $question, User $user): Rubric
    {
        $questionMarks = round((float) $question->marks, 2);
        if ($questionMarks <= 0) {
            throw new RubricException(
                'This question has no marks allocated. Set the question marks before generating a rubric.',
                self::STATUS_VALIDATION
            );
        }

        $payload = $this->buildGenerationPayload($question);

        try {
            $aiResponse = $this->aiService->generateRubric($payload);
        } catch (Exception $e) {
            throw $this->translateAiException($e);
        }

        $normalized = $this->validateAiResponse($aiResponse, $questionMarks);

        $rubric = $this->persistNewVersion($question, $user, $normalized, $questionMarks, [
            'generation_method' => $normalized['generation_method'],
            'ai_model' => $normalized['ai_model'],
            'ai_model_version' => $normalized['ai_model_version'],
        ]);

        $this->auditLogService->log('RUBRIC_GENERATED', $rubric, $rubric->id, [
            'question_id' => $question->id,
            'version' => $rubric->version,
            'criteria_count' => count($normalized['criteria']),
            'generation_method' => $rubric->generation_method,
        ], $user);

        return $rubric->load('criteria');
    }

    /**
     * Validate and normalize the AI service response. Rejects any inconsistent rubric.
     *
     * @throws RubricException
     */
    public function validateAiResponse(array $response, float $questionMarks): array
    {
        $rubric = $response['rubric'] ?? null;
        if (!is_array($rubric)) {
            throw $this->invalidAi('AI response did not contain a rubric.');
        }

        $title = trim((string) ($rubric['title'] ?? ''));
        if ($title === '') {
            throw $this->invalidAi('AI rubric is missing a title.');
        }

        $rubricTotal = $rubric['total_marks'] ?? null;
        if (!is_numeric($rubricTotal) || abs((float) $rubricTotal - $questionMarks) > self::MARK_TOLERANCE) {
            throw $this->invalidAi('AI rubric total does not match the question marks.');
        }

        $criteria = $this->normalizeCriteria($rubric['criteria'] ?? null, $questionMarks, true);

        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $method = strtolower((string) ($response['generation_method'] ?? 'ai_assisted'));
        if (!in_array($method, ['ai_assisted', 'template_based'], true)) {
            $method = 'ai_assisted';
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'general_guidance' => isset($rubric['general_guidance']) ? mb_substr((string) $rubric['general_guidance'], 0, 2000) : null,
            'criteria' => $criteria,
            'generation_method' => $method,
            'ai_model' => isset($metadata['model']) ? mb_substr((string) $metadata['model'], 0, 255) : null,
            'ai_model_version' => isset($metadata['version']) ? mb_substr((string) $metadata['version'], 0, 50) : null,
        ];
    }

    /**
     * Validate criteria structure and marks integrity.
     *
     * @throws RubricException
     */
    public function normalizeCriteria(mixed $criteria, float $questionMarks, bool $fromAi = false): array
    {
        $fail = fn (string $msg) => $fromAi ? $this->invalidAi($msg) : new RubricException($msg, self::STATUS_VALIDATION);

        if (!is_array($criteria) || count($criteria) === 0) {
            throw $fail('A rubric must contain at least one criterion.');
        }
        if (count($criteria) > self::MAX_CRITERIA) {
            throw $fail('A rubric may contain at most ' . self::MAX_CRITERIA . ' criteria.');
        }

        $normalized = [];
        $sum = 0.0;
        foreach (array_values($criteria) as $idx => $c) {
            $n = $idx + 1;
            if (!is_array($c)) {
                throw $fail("Criterion {$n} is malformed.");
            }

            $name = trim((string) ($c['criterion'] ?? ''));
            $description = trim((string) ($c['description'] ?? ''));
            $marks = $c['max_marks'] ?? null;

            if ($name === '') {
                throw $fail("Criterion {$n} must have a name.");
            }
            if ($description === '') {
                throw $fail("Criterion {$n} must have a description.");
            }
            if (!is_numeric($marks) || is_bool($marks)) {
                throw $fail("Criterion {$n} has invalid marks.");
            }
            $marks = round((float) $marks, 2);
            if ($marks < 0) {
                throw $fail("Criterion {$n} has negative marks.");
            }

            $indicators = $c['expected_indicators'] ?? [];
            if (is_string($indicators)) {
                $decoded = json_decode($indicators, true);
                $indicators = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode("\n", $indicators)));
            }
            if (!is_array($indicators)) {
                throw $fail("Criterion {$n} has malformed expected indicators.");
            }
            $indicators = array_values(array_filter(array_map(
                fn ($i) => is_scalar($i) ? mb_substr(trim((string) $i), 0, 255) : '',
                $indicators
            ), fn ($i) => $i !== ''));

            $sum += $marks;
            $normalized[] = [
                'criterion' => mb_substr($name, 0, 255),
                'description' => mb_substr($description, 0, 2000),
                'max_marks' => $marks,
                'scoring_guidance' => isset($c['scoring_guidance']) ? mb_substr(trim((string) $c['scoring_guidance']), 0, 2000) : null,
                'expected_indicators' => array_slice($indicators, 0, 12),
                'sort_order' => $n,
            ];
        }

        if (abs(round($sum, 2) - $questionMarks) > self::MARK_TOLERANCE) {
            throw $fail(sprintf(
                'Criterion marks total %s but the question is worth %s marks.',
                rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($questionMarks, 2, '.', ''), '0'), '.')
            ));
        }

        return $normalized;
    }

    /**
     * Faculty edit of a draft rubric. Criteria are replaced atomically.
     *
     * @throws RubricException
     */
    public function update(Rubric $rubric, User $user, array $data): Rubric
    {
        if ($rubric->isArchived()) {
            throw new RubricException('Archived rubrics are read-only.', self::STATUS_VALIDATION);
        }

        $questionMarks = round((float) $rubric->question->marks, 2);
        $criteria = array_key_exists('criteria', $data)
            ? $this->normalizeCriteria($data['criteria'], $questionMarks)
            : null;

        return DB::transaction(function () use ($rubric, $user, $data, $criteria, $questionMarks) {
            $attributes = [];
            if (array_key_exists('title', $data)) {
                $attributes['title'] = mb_substr(trim((string) $data['title']), 0, 255);
            }
            if (array_key_exists('general_guidance', $data)) {
                $attributes['general_guidance'] = $data['general_guidance'] !== null
                    ? mb_substr(trim((string) $data['general_guidance']), 0, 2000)
                    : null;
            }
            $attributes['total_marks'] = $questionMarks;

            // Editing an approved rubric reverts it to draft so it is re-reviewed before use.
            if ($rubric->isApproved() && ($criteria !== null || isset($attributes['title']) || array_key_exists('general_guidance', $attributes))) {
                $attributes['status'] = Rubric::STATUS_DRAFT;
                $attributes['approved_at'] = null;
                $attributes['approved_by'] = null;
            }

            $rubric->update($attributes);

            if ($criteria !== null) {
                $rubric->criteria()->delete();
                foreach ($criteria as $c) {
                    $rubric->criteria()->create($c);
                }
            }

            $this->auditLogService->log('RUBRIC_UPDATED', $rubric, $rubric->id, [
                'version' => $rubric->version,
                'criteria_replaced' => $criteria !== null,
            ], $user);

            return $rubric->fresh(['criteria']);
        });
    }

    /**
     * Approve a draft rubric after re-validating it. Previously approved versions become ARCHIVED (still readable).
     *
     * @throws RubricException
     */
    public function approve(Rubric $rubric, User $user): Rubric
    {
        if (!$rubric->isDraft()) {
            throw new RubricException('Only draft rubrics can be approved.', self::STATUS_VALIDATION);
        }

        $rubric->load(['criteria', 'question']);
        $questionMarks = round((float) $rubric->question->marks, 2);

        if (trim((string) $rubric->title) === '') {
            throw new RubricException('Rubric title is required before approval.', self::STATUS_VALIDATION);
        }

        $this->normalizeCriteria($rubric->criteria->map(fn ($c) => [
            'criterion' => $c->criterion,
            'description' => $c->description,
            'max_marks' => $c->max_marks,
            'scoring_guidance' => $c->scoring_guidance,
            'expected_indicators' => $c->expected_indicators ?? [],
        ])->all(), $questionMarks);

        return DB::transaction(function () use ($rubric, $user, $questionMarks) {
            Rubric::where('question_id', $rubric->question_id)
                ->where('id', '!=', $rubric->id)
                ->where('status', Rubric::STATUS_APPROVED)
                ->update(['status' => Rubric::STATUS_ARCHIVED]);

            $rubric->update([
                'status' => Rubric::STATUS_APPROVED,
                'total_marks' => $questionMarks,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            $this->auditLogService->log('RUBRIC_APPROVED', $rubric, $rubric->id, [
                'question_id' => $rubric->question_id,
                'version' => $rubric->version,
            ], $user);

            return $rubric->fresh(['criteria']);
        });
    }

    /**
     * Delete a rubric and its criteria. The question is never affected.
     */
    public function delete(Rubric $rubric, User $user): void
    {
        DB::transaction(function () use ($rubric, $user) {
            $this->auditLogService->log('RUBRIC_DELETED', $rubric, $rubric->id, [
                'question_id' => $rubric->question_id,
                'version' => $rubric->version,
                'status' => $rubric->status,
            ], $user);

            $rubric->criteria()->delete();
            $rubric->delete();
        });
    }

    /**
     * Persist rubric + criteria atomically as the next version for the question.
     *
     * @throws RubricException
     */
    protected function persistNewVersion(Question $question, User $user, array $normalized, float $questionMarks, array $meta): Rubric
    {
        try {
            return DB::transaction(function () use ($question, $user, $normalized, $questionMarks, $meta) {
                $nextVersion = ((int) Rubric::where('question_id', $question->id)->lockForUpdate()->max('version')) + 1;

                $rubric = Rubric::create([
                    'question_id' => $question->id,
                    'assessment_id' => $question->assessment_id,
                    'created_by' => $user->id,
                    'title' => $normalized['title'],
                    'total_marks' => $questionMarks,
                    'status' => Rubric::STATUS_DRAFT,
                    'version' => $nextVersion,
                    'generation_method' => $meta['generation_method'] ?? 'ai_assisted',
                    'ai_model' => $meta['ai_model'] ?? null,
                    'ai_model_version' => $meta['ai_model_version'] ?? null,
                    'general_guidance' => $normalized['general_guidance'],
                    'generated_at' => now(),
                ]);

                foreach ($normalized['criteria'] as $c) {
                    RubricCriterion::create($c + ['rubric_id' => $rubric->id]);
                }

                return $rubric;
            });
        } catch (RubricException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Rubric persistence failed for question ' . $question->id . ': ' . $e->getMessage());
            throw new RubricException('The rubric could not be saved. Please try again.', 500);
        }
    }

    protected function invalidAi(string $reason): RubricException
    {
        Log::warning('AI rubric rejected: ' . $reason);

        return new RubricException(
            'FacultyLens could not produce a valid rubric for this question. Please try again or create the rubric manually.',
            self::STATUS_AI_INVALID
        );
    }

    protected function translateAiException(Exception $e): RubricException
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return new RubricException('Rubric generation took too long. Please try again.', self::STATUS_AI_TIMEOUT);
        }
        if (str_contains($msg, 'unavailable') || str_contains($msg, 'unreachable')) {
            return new RubricException('Rubric generation is temporarily unavailable. Please try again later.', self::STATUS_AI_UNAVAILABLE);
        }
        if (str_contains($msg, 'validation') || str_contains($msg, 'unsupported') || str_contains($msg, 'cannot be empty') || str_contains($msg, 'greater than zero')) {
            return new RubricException($e->getMessage(), self::STATUS_VALIDATION);
        }

        return new RubricException(
            'FacultyLens could not produce a valid rubric for this question. Please try again or create the rubric manually.',
            self::STATUS_AI_INVALID
        );
    }
}
