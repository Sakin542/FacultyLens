<?php

namespace App\Services;

use App\Jobs\RunAiEvaluationJob;
use App\Models\AiEvaluationDataset;
use App\Models\AiEvaluationExample;
use App\Models\AiEvaluationPrediction;
use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;
use App\Models\AiGradingResult;
use App\Models\AiModel;
use App\Models\AiPromptVersion;
use App\Models\Course;
use App\Models\GeneratedQuestion;
use App\Models\RecommendationFeedback;
use App\Models\Rubric;
use App\Models\User;
use App\Services\AiEvaluation\AlignmentEvaluator;
use App\Services\AiEvaluation\ClassificationEvaluator;
use App\Services\AiEvaluation\ClassificationTaskEvaluator;
use App\Services\AiEvaluation\EvaluationReportService;
use App\Services\AiEvaluation\GradingEvaluator;
use App\Services\AiEvaluation\QuestionGenerationEvaluator;
use App\Services\AiEvaluation\RagEvaluator;
use App\Services\AiEvaluation\RubricEvaluator;
use App\Services\AiEvaluation\SimilarityEvaluator;
use App\Services\AiEvaluation\TaskEvaluator;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 35: orchestrates datasets → runs → inference (production AI service) → metrics → persistence.
 * Evaluation is read-only with respect to production AI (no threshold/prompt/model changes).
 */
class AiEvaluationService
{
    public function __construct(
        protected AiService $ai,
        protected ClassificationEvaluator $classification,
        protected EvaluationReportService $report,
        protected AuditLogService $audit,
        protected CourseAccessService $access,
    ) {}

    // ------------------------------------------------------------------ registry

    /** Sync the model registry + prompt versions from the AI service inventory (idempotent). */
    public function syncRegistry(?User $actor = null): array
    {
        $inv = $this->ai->evaluationInventory();
        $registered = [];
        $upsert = function (array $m, string $task) use (&$registered) {
            $model = AiModel::updateOrCreate(
                ['model_name' => $m['model_name'], 'task' => $task, 'version' => $m['version'] ?? 'configured'],
                ['provider' => $m['provider'] ?? 'FacultyLens', 'model_type' => $m['model_type'], 'configuration' => $m['configuration'] ?? null, 'is_active' => true]
            );
            $registered[] = $model->id;
        };
        foreach (['SIMILARITY', 'LO_ALIGNMENT'] as $task) {
            $upsert($inv['embedding_model'], $task);
        }
        foreach ($inv['engines'] ?? [] as $engine) {
            foreach ($engine['tasks'] ?? [] as $task) {
                $upsert($engine, $task);
            }
        }
        if (!empty($inv['generation_model']['configuration']['configured'])) {
            foreach (['DOCUMENT_CHAT', 'QUESTION_GENERATION'] as $task) {
                $upsert($inv['generation_model'], $task);
            }
        }
        foreach ($inv['prompt_versions'] ?? [] as $p) {
            AiPromptVersion::updateOrCreate(['feature' => $p['feature'], 'version' => $p['version']], ['prompt_hash' => $p['prompt_hash'] ?? null, 'description' => $p['description'] ?? null, 'is_active' => true]);
        }
        if ($actor) {
            $this->audit->log('AI_MODEL_REGISTERED', 'AiModel', null, ['count' => count($registered)], $actor);
        }

        return ['models' => AiModel::orderBy('task')->get(), 'prompt_versions' => AiPromptVersion::orderBy('feature')->get(), 'thresholds' => $inv['thresholds'] ?? null];
    }

    // ------------------------------------------------------------------ datasets

    public function createDataset(User $user, array $data): AiEvaluationDataset
    {
        if (!empty($data['course_id'])) {
            $course = Course::find($data['course_id']);
            if (!$course || !$this->access->can($user, $course, 'view')) {
                throw new HttpException(403, 'Unauthorized access to course.');
            }
        }
        $dataset = AiEvaluationDataset::create([
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'task' => $data['task'], 'version' => $data['version'] ?? 'v1',
            'source' => $data['source'] ?? 'FACULTY_VALIDATED', 'split' => $data['split'] ?? 'TEST', 'status' => AiEvaluationDataset::STATUS_DRAFT,
            'course_id' => $data['course_id'] ?? null, 'created_by' => $user->id,
        ]);
        $this->audit->log('AI_EVALUATION_DATASET_CREATED', $dataset, $dataset->id, ['task' => $dataset->task, 'source' => $dataset->source], $user);

        return $dataset;
    }

    /**
     * @param array<int, array{input_data: array, expected_output: array, metadata?: array, source?: string, split?: string}> $examples
     * @return array{added:int, skipped_duplicates:int}
     */
    public function addExamples(User $user, AiEvaluationDataset $dataset, array $examples): array
    {
        $max = (int) config('ai_evaluation.max_examples', 5000);
        if ($dataset->examples()->count() + count($examples) > $max) {
            throw new HttpException(422, "A dataset may hold at most {$max} examples.");
        }
        $existing = $dataset->examples()->pluck('fingerprint')->flip()->all();
        $added = $skipped = 0;
        DB::transaction(function () use ($user, $dataset, $examples, &$existing, &$added, &$skipped) {
            foreach ($examples as $ex) {
                $input = (array) ($ex['input_data'] ?? []);
                $fp = AiEvaluationExample::fingerprintFor($input);
                if (isset($existing[$fp])) {
                    $skipped++;
                    continue;
                }
                $existing[$fp] = true;
                AiEvaluationExample::create(['dataset_id' => $dataset->id, 'input_data' => $input, 'expected_output' => (array) ($ex['expected_output'] ?? []), 'metadata' => $ex['metadata'] ?? null,
                    'source' => $ex['source'] ?? $dataset->source, 'split' => $ex['split'] ?? $dataset->split, 'fingerprint' => $fp, 'created_by' => $user->id]);
                $added++;
            }
            $dataset->update(['status' => AiEvaluationDataset::STATUS_DRAFT, 'validation_report' => null, 'validated_at' => null]);
        });
        $this->audit->log('AI_EVALUATION_DATASET_UPDATED', $dataset, $dataset->id, ['added' => $added, 'skipped_duplicates' => $skipped], $user);

        return ['added' => $added, 'skipped_duplicates' => $skipped];
    }

    /**
     * Validate every example against the task schema. Marks the dataset READY only when all examples are valid.
     */
    public function validateDataset(AiEvaluationDataset $dataset): array
    {
        $evaluator = $this->evaluatorFor($dataset->task);
        $total = $valid = $invalid = 0;
        $problems = [];
        $labels = [];
        $seen = [];
        $duplicates = 0;
        $conflicts = 0;
        $labelKey = $this->labelKey($dataset->task);
        foreach ($dataset->examples()->orderBy('id')->cursor() as $ex) {
            $total++;
            $errors = $evaluator->validateExample((array) $ex->input_data, (array) $ex->expected_output);
            if (isset($seen[$ex->fingerprint])) {
                $duplicates++;
                if ($seen[$ex->fingerprint] !== json_encode($ex->expected_output)) {
                    $conflicts++;
                    $errors[] = 'Conflicting label for a duplicate input.';
                } else {
                    $errors[] = 'Duplicate example.';
                }
            } else {
                $seen[$ex->fingerprint] = json_encode($ex->expected_output);
            }
            if ($errors) {
                $invalid++;
                if (count($problems) < 50) {
                    $problems[] = ['example_id' => $ex->id, 'errors' => $errors];
                }
            } else {
                $valid++;
            }
            if ($labelKey && isset($ex->expected_output[$labelKey]) && is_scalar($ex->expected_output[$labelKey])) {
                $l = strtoupper((string) $ex->expected_output[$labelKey]);
                $labels[$l] = ($labels[$l] ?? 0) + 1;
            }
        }
        $report = [
            'task' => $dataset->task, 'total_examples' => $total, 'valid_examples' => $valid, 'invalid_examples' => $invalid, 'duplicate_examples' => $duplicates,
            'conflicting_labels' => $conflicts, 'label_distribution' => $labels, 'problems' => $problems, 'is_valid' => $total > 0 && $invalid === 0,
            'size_category' => $this->report->sizeCategory($total), 'small_dataset_warning' => $total < (int) config('ai_evaluation.min_examples_warning', 30),
            'validated_at' => now()->toIso8601String(),
        ];
        if ($total === 0) {
            $report['problems'][] = ['example_id' => null, 'errors' => ['Dataset is empty.']];
        }
        $dataset->update(['validation_report' => $report, 'validated_at' => now(), 'status' => $report['is_valid'] ? AiEvaluationDataset::STATUS_READY : AiEvaluationDataset::STATUS_DRAFT]);

        return $report;
    }

    // ------------------------------------------------------------------ runs

    public function startRun(User $user, AiEvaluationDataset $dataset, array $options = []): AiEvaluationRun
    {
        if (!config('ai_evaluation.enabled', true)) {
            throw new HttpException(503, 'AI evaluation is disabled.');
        }
        $report = $dataset->validation_report;
        if (!$report || !($report['is_valid'] ?? false) || $dataset->status === AiEvaluationDataset::STATUS_DRAFT) {
            $report = $this->validateDataset($dataset);
            if (!$report['is_valid']) {
                throw new HttpException(422, 'Dataset validation failed; fix the invalid examples before running an evaluation.');
            }
        }
        if ($dataset->runs()->whereIn('status', [AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::STATUS_RUNNING])->exists()) {
            throw new HttpException(409, 'An evaluation for this dataset is already running.');
        }
        $this->syncRegistryQuietly();
        $model = AiModel::where('task', $dataset->task)->where('is_active', true)->orderByDesc('id')->first();
        $prompt = AiPromptVersion::where('feature', $this->promptFeature($dataset->task))->where('is_active', true)->orderByDesc('id')->first();

        $run = AiEvaluationRun::create([
            'dataset_id' => $dataset->id, 'task' => $dataset->task, 'model_id' => $model?->id, 'prompt_version_id' => $prompt?->id,
            'configuration' => ['split' => $options['split'] ?? $dataset->split, 'batch_size' => (int) config('ai_evaluation.batch_size'), 'thresholds' => [
                'similarity' => config('ai_evaluation.similarity_thresholds'), 'alignment' => config('ai_evaluation.alignment_thresholds')], 'note' => $options['note'] ?? null],
            'status' => AiEvaluationRun::STATUS_PENDING, 'example_count' => $dataset->examples()->count(), 'created_by' => $user->id,
        ]);
        $dataset->update(['status' => AiEvaluationDataset::STATUS_RUNNING]);
        $this->audit->log('AI_EVALUATION_STARTED', $run, $run->id, ['task' => $run->task, 'dataset_id' => $dataset->id, 'example_count' => $run->example_count, 'model' => $model?->model_name], $user);

        RunAiEvaluationJob::dispatch($run->id);

        return $run->fresh();
    }

    /** Executed by RunAiEvaluationJob. Idempotent: predictions are upserted on (run, example). */
    public function executeRun(AiEvaluationRun $run): void
    {
        $run = $run->fresh(['dataset']);
        if (!$run || in_array($run->status, [AiEvaluationRun::STATUS_COMPLETED, AiEvaluationRun::STATUS_CANCELLED], true)) {
            return;
        }
        $run->update(['status' => AiEvaluationRun::STATUS_RUNNING, 'started_at' => $run->started_at ?? now(), 'failure_reason' => null]);
        $evaluator = $this->evaluatorFor($run->task);

        try {
            $examples = $run->dataset->examples()->orderBy('id')->get();
            $t0 = microtime(true);
            $predictions = $evaluator->predict($examples);
            $inferenceMs = (int) round((microtime(true) - $t0) * 1000);

            $failures = collect($predictions)->where('error_type', 'INFERENCE_ERROR')->count();
            if ($examples->count() > 0 && $failures === $examples->count()) {
                throw new Exception('AI service inference failed for every example: ' . ($predictions[$examples->first()->id]['metadata']['message'] ?? 'unknown error'));
            }

            $rows = $evaluator->aggregate($examples, $predictions);
            $rows['inference_failures'] = $rows['inference_failures'] ?? ['value' => $failures, 'metadata' => null];
            $metrics = collect($rows)->filter(fn ($r) => $r['value'] !== null)->map(fn ($r) => (float) $r['value'])->all();
            $gates = $this->report->evaluateGates($run->task, $metrics, $examples->count());
            $regression = $this->report->regression($run, $metrics);
            $errorBreakdown = collect($predictions)->pluck('error_type')->filter()->countBy()->all();
            $headline = EvaluationReportService::headlineMetrics()[$run->task];

            DB::transaction(function () use ($run, $examples, $predictions, $rows, $gates, $regression, $errorBreakdown, $headline, $metrics, $inferenceMs) {
                foreach ($examples as $ex) {
                    $p = $predictions[$ex->id] ?? ['prediction' => null, 'is_correct' => null, 'score' => null, 'error_type' => 'INFERENCE_ERROR', 'metadata' => null];
                    AiEvaluationPrediction::updateOrCreate(['evaluation_run_id' => $run->id, 'example_id' => $ex->id], [
                        'prediction' => $p['prediction'], 'expected_output' => $ex->expected_output, 'is_correct' => $p['is_correct'], 'score' => $p['score'], 'error_type' => $p['error_type'], 'metadata' => $p['metadata']]);
                }
                foreach ($rows as $name => $r) {
                    AiEvaluationResult::updateOrCreate(['evaluation_run_id' => $run->id, 'metric_name' => $name], ['metric_value' => $r['value'], 'metric_metadata' => $r['metadata']]);
                }
                $run->update([
                    'status' => AiEvaluationRun::STATUS_COMPLETED, 'gate_status' => $gates['status'], 'processed_count' => $examples->count(), 'inference_ms' => $inferenceMs, 'completed_at' => now(),
                    'summary' => ['headline_metric' => $headline, 'headline_value' => $metrics[$headline] ?? null, 'metrics' => $metrics, 'gates' => $gates['gates'], 'warnings' => $gates['warnings'],
                        'regression' => $regression, 'error_breakdown' => $errorBreakdown, 'size_category' => $this->report->sizeCategory($examples->count()), 'limitations' => $this->report->limitations($run->task, $examples->count())],
                ]);
                $run->dataset->update(['status' => AiEvaluationDataset::STATUS_COMPLETED]);
            });

            $this->audit->log('AI_EVALUATION_COMPLETED', $run, $run->id, ['task' => $run->task, 'gate_status' => $gates['status'], 'headline' => $headline, 'value' => $metrics[$headline] ?? null, 'regression' => $regression['regression']], $run->creator);
        } catch (\Throwable $e) {
            Log::warning("AiEvaluationService: run {$run->id} failed: {$e->getMessage()}");
            $run->update(['status' => AiEvaluationRun::STATUS_FAILED, 'failure_reason' => 'Evaluation failed: ' . mb_substr($e->getMessage(), 0, 500), 'completed_at' => now()]);
            $run->dataset->update(['status' => AiEvaluationDataset::STATUS_READY]);
            $this->audit->log('AI_EVALUATION_FAILED', $run, $run->id, ['task' => $run->task], $run->creator);
            throw $e;
        }
    }

    public function cancelRun(AiEvaluationRun $run): void
    {
        if (in_array($run->status, [AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::STATUS_RUNNING], true)) {
            $run->update(['status' => AiEvaluationRun::STATUS_CANCELLED, 'completed_at' => now()]);
            $run->dataset->update(['status' => AiEvaluationDataset::STATUS_READY]);
        }
    }

    // ------------------------------------------------------------------ dashboard

    /**
     * Latest completed run per task for the user (owned datasets; admin sees all). "Not evaluated yet" when none.
     */
    public function overview(User $user): array
    {
        $runs = $this->visibleRuns($user)->where('status', AiEvaluationRun::STATUS_COMPLETED)->with(['dataset:id,name,version', 'model:id,model_name,version,model_type', 'promptVersion:id,feature,version'])->orderByDesc('id')->get()->unique('task');
        $tasks = [];
        foreach ((array) config('ai_evaluation.tasks') as $task) {
            $run = $runs->firstWhere('task', $task);
            $tasks[] = ['task' => $task, 'evaluated' => (bool) $run, 'run' => $run ? $this->report->runHeader($run) : null,
                'headline_metric' => EvaluationReportService::headlineMetrics()[$task], 'headline_value' => $run?->summary['headline_value'] ?? null,
                'gate_status' => $run?->gate_status, 'regression' => $run?->summary['regression']['regression'] ?? false, 'size_category' => $run?->summary['size_category'] ?? null, 'warnings' => $run?->summary['warnings'] ?? []];
        }
        $statuses = collect($tasks)->where('evaluated', true)->pluck('gate_status');
        $overall = $statuses->isEmpty() ? 'NOT_EVALUATED' : ($statuses->contains(AiEvaluationRun::GATE_FAILED) ? 'FAILED' : ($statuses->contains(AiEvaluationRun::GATE_WARNINGS) ? 'PASSED_WITH_WARNINGS' : 'PASSED'));
        if ($this->visibleRuns($user)->whereIn('status', [AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::STATUS_RUNNING])->exists()) {
            $overall = 'EVALUATING';
        }

        return ['overall_status' => $overall, 'tasks' => $tasks, 'models' => AiModel::where('is_active', true)->orderBy('task')->get(), 'prompt_versions' => AiPromptVersion::where('is_active', true)->get(),
            'faculty_signals' => $this->facultySignals($user), 'dataset_count' => $this->visibleDatasets($user)->count(), 'run_count' => $this->visibleRuns($user)->count(), 'limitations' => config('ai_evaluation.limitations')];
    }

    /** Real-world faculty interaction signals from production tables (owner/collaborator scope). Not accuracy metrics. */
    public function facultySignals(User $user): array
    {
        $courseIds = $this->access->accessibleCourseIds($user);
        if ($courseIds === []) {
            return ['recommendations' => [], 'generated_questions' => [], 'rubrics' => [], 'grading' => [], 'note' => 'Faculty interaction signals reflect review decisions, not AI correctness.'];
        }
        $recs = RecommendationFeedback::whereHas('recommendation.analysisReport.assessment', fn ($q) => $q->whereIn('course_id', $courseIds))->selectRaw('decision, COUNT(*) as c')->groupBy('decision')->pluck('c', 'decision')->all();
        $gq = GeneratedQuestion::whereHas('request', fn ($q) => $q->whereIn('course_id', $courseIds))->selectRaw('review_status, COUNT(*) as c')->groupBy('review_status')->pluck('c', 'review_status')->all();
        $gqEdited = GeneratedQuestion::whereHas('request', fn ($q) => $q->whereIn('course_id', $courseIds))->where('version', '>', 1)->count();
        $gqRegen = GeneratedQuestion::whereHas('request', fn ($q) => $q->whereIn('course_id', $courseIds))->whereNotNull('regenerated_from_id')->count();
        $rubrics = Rubric::whereHas('assessment', fn ($q) => $q->whereIn('course_id', $courseIds))->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $grading = AiGradingResult::whereHas('submission.assessment', fn ($q) => $q->whereIn('course_id', $courseIds))->whereNotNull('faculty_decision')->selectRaw('faculty_decision, COUNT(*) as c')->groupBy('faculty_decision')->pluck('c', 'faculty_decision')->all();

        return ['recommendations' => $recs, 'generated_questions' => $gq + ['EDITED' => $gqEdited, 'REGENERATED' => $gqRegen], 'rubrics' => $rubrics, 'grading' => $grading,
            'note' => 'Faculty interaction signals reflect review decisions, not AI correctness.'];
    }

    public function history(User $user, array $filters = [])
    {
        $q = $this->visibleRuns($user)->with(['dataset:id,name,version', 'model:id,model_name,version', 'promptVersion:id,feature,version'])->orderByDesc('id');
        foreach (['task', 'model_id', 'prompt_version_id', 'dataset_id', 'status'] as $f) {
            if (!empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (!empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }

        return $q->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function visibleDatasets(User $user)
    {
        return $user->isAdmin() ? AiEvaluationDataset::query() : AiEvaluationDataset::where('created_by', $user->id);
    }

    public function visibleRuns(User $user)
    {
        return $user->isAdmin() ? AiEvaluationRun::query() : AiEvaluationRun::where('created_by', $user->id);
    }

    public function canAccessDataset(User $user, AiEvaluationDataset $dataset): bool
    {
        return $user->isAdmin() || $dataset->created_by === $user->id;
    }

    public function canAccessRun(User $user, AiEvaluationRun $run): bool
    {
        return $user->isAdmin() || $run->created_by === $user->id;
    }

    // ------------------------------------------------------------------ helpers

    public function evaluatorFor(string $task): TaskEvaluator
    {
        return match ($task) {
            'QUESTION_CLASSIFICATION', 'DIFFICULTY_CLASSIFICATION', 'BLOOM_CLASSIFICATION' => new ClassificationTaskEvaluator($this->ai, $this->classification, $task),
            'LO_ALIGNMENT', 'ANSWER_RUBRIC_ALIGNMENT' => new AlignmentEvaluator($this->ai, $this->classification, $task),
            'SIMILARITY' => new SimilarityEvaluator($this->ai, $this->classification),
            'RUBRIC_GENERATION' => new RubricEvaluator($this->ai, $this->classification),
            'GRADING_ASSISTANCE' => new GradingEvaluator($this->ai, $this->classification),
            'DOCUMENT_CHAT' => new RagEvaluator($this->ai, $this->classification),
            'QUESTION_GENERATION' => new QuestionGenerationEvaluator($this->ai, $this->classification),
            default => throw new HttpException(422, "Unsupported evaluation task '{$task}'."),
        };
    }

    protected function labelKey(string $task): ?string
    {
        return ['QUESTION_CLASSIFICATION' => 'expected_type', 'DIFFICULTY_CLASSIFICATION' => 'expected_difficulty', 'BLOOM_CLASSIFICATION' => 'expected_cognitive_level',
            'LO_ALIGNMENT' => 'expected_alignment', 'SIMILARITY' => 'expected_relationship', 'DOCUMENT_CHAT' => 'answer_present', 'RUBRIC_GENERATION' => 'decision'][$task] ?? null;
    }

    protected function promptFeature(string $task): ?string
    {
        return ['DOCUMENT_CHAT' => 'document_chat', 'QUESTION_GENERATION' => 'question_generation', 'RUBRIC_GENERATION' => 'rubric_generation', 'GRADING_ASSISTANCE' => 'grading_assistance'][$task] ?? null;
    }

    protected function syncRegistryQuietly(): void
    {
        try {
            $this->syncRegistry();
        } catch (\Throwable $e) {
            Log::info('AiEvaluationService: registry sync skipped: ' . $e->getMessage());
        }
    }
}
