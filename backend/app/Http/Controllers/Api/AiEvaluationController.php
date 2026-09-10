<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiEvaluationDataset;
use App\Models\AiEvaluationExample;
use App\Models\AiEvaluationRating;
use App\Models\AiEvaluationRun;
use App\Models\AiModel;
use App\Models\AiPromptVersion;
use App\Services\AiEvaluation\EvaluationReportService;
use App\Services\AiEvaluationService;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 35: AI Evaluation & Model Performance API. Every metric shown comes from persisted evaluation runs —
 * nothing is hard-coded, and nothing here modifies production AI settings.
 */
class AiEvaluationController extends Controller
{
    public function __construct(protected AiEvaluationService $service, protected EvaluationReportService $report, protected AuditLogService $audit) {}

    /** GET /api/ai/evaluation */
    public function index(Request $request): JsonResponse
    {
        return $this->ok('AI evaluation overview retrieved.', $this->service->overview($request->user()));
    }

    // ------------------------------------------------------------------ registry

    /** GET /api/ai/evaluation/models */
    public function models(Request $request): JsonResponse
    {
        if ($request->boolean('sync')) {
            try {
                return $this->ok('Model registry synced.', $this->service->syncRegistry($request->user()));
            } catch (\Throwable $e) {
                return $this->error('The AI service inventory is unavailable; showing the stored registry.', 503, ['models' => AiModel::orderBy('task')->get(), 'prompt_versions' => AiPromptVersion::all()]);
            }
        }

        return $this->ok('Model registry retrieved.', ['models' => AiModel::orderBy('task')->get(), 'prompt_versions' => AiPromptVersion::orderBy('feature')->get()]);
    }

    /** GET /api/ai/evaluation/prompts */
    public function prompts(): JsonResponse
    {
        return $this->ok('Prompt versions retrieved.', AiPromptVersion::orderBy('feature')->orderByDesc('id')->get());
    }

    // ------------------------------------------------------------------ datasets

    /** GET /api/ai/evaluation/datasets */
    public function datasets(Request $request): JsonResponse
    {
        $q = $this->service->visibleDatasets($request->user())->withCount(['examples', 'runs'])->with('creator:id,name')->orderByDesc('id');
        if ($request->filled('task')) {
            $q->where('task', $request->input('task'));
        }

        return $this->ok('Datasets retrieved.', $q->get()->map(fn ($d) => $this->datasetPayload($d))->values());
    }

    /** POST /api/ai/evaluation/datasets */
    public function storeDataset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
            'task' => ['required', Rule::in(config('ai_evaluation.tasks'))], 'version' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', Rule::in(config('ai_evaluation.sources'))], 'split' => ['nullable', Rule::in(config('ai_evaluation.splits'))],
            'course_id' => ['nullable', 'integer'],
            'examples' => ['nullable', 'array', 'max:' . config('ai_evaluation.max_examples')],
            'examples.*.input_data' => ['nullable', 'array'], 'examples.*.expected_output' => ['nullable', 'array'],
            'examples.*.metadata' => ['nullable', 'array'], 'examples.*.source' => ['nullable', Rule::in(config('ai_evaluation.sources'))], 'examples.*.split' => ['nullable', Rule::in(config('ai_evaluation.splits'))],
        ]);
        try {
            $dataset = $this->service->createDataset($request->user(), $data);
            $added = !empty($data['examples']) ? $this->service->addExamples($request->user(), $dataset, $data['examples']) : ['added' => 0, 'skipped_duplicates' => 0];
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
        $dataset->loadCount(['examples', 'runs'])->load('creator:id,name');

        return $this->ok('Dataset created.', $this->datasetPayload($dataset) + ['import' => $added], 201);
    }

    /** GET /api/ai/evaluation/datasets/{dataset} */
    public function showDataset(Request $request, AiEvaluationDataset $dataset): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        $dataset->loadCount(['examples', 'runs'])->load('creator:id,name');
        $examples = $dataset->examples()->orderBy('id')->paginate(min(max((int) $request->get('per_page', 25), 1), 200));

        return $this->ok('Dataset retrieved.', $this->datasetPayload($dataset) + [
            'examples' => collect($examples->items())->map(fn ($e) => ['id' => $e->id, 'input_data' => $e->input_data, 'expected_output' => $e->expected_output, 'metadata' => $e->metadata, 'source' => $e->source, 'split' => $e->split])->values(),
            'examples_meta' => ['current_page' => $examples->currentPage(), 'last_page' => $examples->lastPage(), 'total' => $examples->total()],
            'runs' => $dataset->runs()->with(['model:id,model_name,version', 'promptVersion:id,feature,version'])->limit(20)->get()->map(fn ($r) => $this->runPayload($r))->values(),
        ]);
    }

    /** PUT /api/ai/evaluation/datasets/{dataset} — metadata and/or append examples */
    public function updateDataset(Request $request, AiEvaluationDataset $dataset): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'version' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in([AiEvaluationDataset::STATUS_ARCHIVED, AiEvaluationDataset::STATUS_DRAFT])],
            'examples' => ['nullable', 'array', 'max:' . config('ai_evaluation.max_examples')],
            'examples.*.input_data' => ['nullable', 'array'], 'examples.*.expected_output' => ['nullable', 'array'], 'examples.*.metadata' => ['nullable', 'array'],
        ]);
        $dataset->update(array_intersect_key($data, array_flip(['name', 'description', 'version', 'status'])));
        $added = null;
        if (!empty($data['examples'])) {
            try {
                $added = $this->service->addExamples($request->user(), $dataset, $data['examples']);
            } catch (HttpException $e) {
                return $this->error($e->getMessage(), $e->getStatusCode());
            }
        } else {
            $this->audit->log('AI_EVALUATION_DATASET_UPDATED', $dataset, $dataset->id, ['fields' => array_keys($data)], $request->user());
        }
        $dataset->refresh()->loadCount(['examples', 'runs'])->load('creator:id,name');

        return $this->ok('Dataset updated.', $this->datasetPayload($dataset) + ['import' => $added]);
    }

    /** DELETE /api/ai/evaluation/datasets/{dataset} */
    public function destroyDataset(Request $request, AiEvaluationDataset $dataset): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        if ($dataset->runs()->whereIn('status', [AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::STATUS_RUNNING])->exists()) {
            return $this->error('Cannot delete a dataset while an evaluation is running.', 409);
        }
        $dataset->delete();

        return $this->ok('Dataset deleted.', null);
    }

    /** DELETE /api/ai/evaluation/datasets/{dataset}/examples/{example} */
    public function destroyExample(Request $request, AiEvaluationDataset $dataset, AiEvaluationExample $example): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        if ($example->dataset_id !== $dataset->id) {
            return $this->error('Example not found in this dataset.', 404);
        }
        $example->delete();
        $dataset->update(['status' => AiEvaluationDataset::STATUS_DRAFT, 'validation_report' => null]);

        return $this->ok('Example removed.', null);
    }

    /** POST /api/ai/evaluation/datasets/{dataset}/validate */
    public function validateDataset(Request $request, AiEvaluationDataset $dataset): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        try {
            $report = $this->service->validateDataset($dataset);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok($report['is_valid'] ? 'Dataset is valid and ready.' : 'Dataset has validation problems.', $report);
    }

    /** POST /api/ai/evaluation/datasets/{dataset}/run */
    public function run(Request $request, AiEvaluationDataset $dataset): JsonResponse
    {
        if ($denied = $this->denyDataset($request, $dataset)) {
            return $denied;
        }
        $opts = $request->validate(['note' => ['nullable', 'string', 'max:255'], 'split' => ['nullable', Rule::in(config('ai_evaluation.splits'))]]);
        try {
            $run = $this->service->startRun($request->user(), $dataset, $opts);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
        $run->load(['dataset:id,name,version', 'model:id,model_name,version', 'promptVersion:id,feature,version']);

        return $this->ok($run->status === AiEvaluationRun::STATUS_COMPLETED ? 'Evaluation completed.' : 'Evaluation queued.', $this->runPayload($run), 202);
    }

    // ------------------------------------------------------------------ runs

    /** GET /api/ai/evaluation/runs */
    public function runs(Request $request): JsonResponse
    {
        $page = $this->service->history($request->user(), $request->only(['task', 'model_id', 'prompt_version_id', 'dataset_id', 'status', 'from', 'per_page']));

        return response()->json(['status' => 'success', 'message' => 'Evaluation runs retrieved.', 'data' => collect($page->items())->map(fn ($r) => $this->runPayload($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /** GET /api/ai/evaluation/runs/{run} */
    public function showRun(Request $request, AiEvaluationRun $run): JsonResponse
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }
        $run->load(['dataset:id,name,version,source,split', 'model', 'promptVersion', 'results']);

        return $this->ok('Evaluation run retrieved.', $this->runPayload($run) + ['metrics' => $this->metricsPayload($run), 'limitations' => $run->summary['limitations'] ?? $this->report->limitations($run->task, $run->example_count)]);
    }

    /** GET /api/ai/evaluation/runs/{run}/metrics */
    public function metrics(Request $request, AiEvaluationRun $run): JsonResponse
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }
        $run->load('results');

        return $this->ok('Metrics retrieved.', $this->metricsPayload($run));
    }

    /** GET /api/ai/evaluation/runs/{run}/errors?error_type=&page= */
    public function errors(Request $request, AiEvaluationRun $run): JsonResponse
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }
        $q = $run->predictions()->with('example:id,input_data,metadata')->orderBy('id');
        if ($request->boolean('only_errors', true)) {
            $q->where(fn ($w) => $w->where('is_correct', false)->orWhereNotNull('error_type'));
        }
        if ($request->filled('error_type')) {
            $q->where('error_type', $request->input('error_type'));
        }
        $page = $q->paginate(min(max((int) $request->get('per_page', 25), 1), 100));

        return response()->json(['status' => 'success', 'message' => 'Example-level results retrieved.',
            'data' => collect($page->items())->map(fn ($p) => ['id' => $p->id, 'example_id' => $p->example_id, 'input' => $this->safeInput($p->example?->input_data ?? []), 'expected_output' => $p->expected_output,
                'prediction' => $p->prediction, 'is_correct' => $p->is_correct, 'score' => $p->score, 'error_type' => $p->error_type, 'metadata' => $p->metadata])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'error_breakdown' => $run->summary['error_breakdown'] ?? []]]);
    }

    /** POST /api/ai/evaluation/runs/{run}/cancel */
    public function cancel(Request $request, AiEvaluationRun $run): JsonResponse
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }
        $this->service->cancelRun($run);

        return $this->ok('Evaluation cancelled.', $this->runPayload($run->fresh()));
    }

    /** GET /api/ai/evaluation/compare?run_a=&run_b= */
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate(['run_a' => ['required', 'integer'], 'run_b' => ['required', 'integer']]);
        $a = AiEvaluationRun::with('results')->find($data['run_a']);
        $b = AiEvaluationRun::with('results')->find($data['run_b']);
        if (!$a || !$b) {
            return $this->error('Run not found.', 404);
        }
        if (!$this->service->canAccessRun($request->user(), $a) || !$this->service->canAccessRun($request->user(), $b)) {
            return $this->error('Unauthorized access to evaluation run.', 403);
        }

        return $this->ok('Runs compared.', $this->report->compare($a, $b));
    }

    /** GET /api/ai/evaluation/runs/{run}/report — JSON report body */
    public function reportJson(Request $request, AiEvaluationRun $run): JsonResponse
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }

        return $this->ok('Evaluation report generated.', $this->reportBody($run));
    }

    /** GET /api/ai/evaluation/runs/{run}/export?format=json|csv|pdf */
    public function export(Request $request, AiEvaluationRun $run): JsonResponse|Response
    {
        if ($denied = $this->denyRun($request, $run)) {
            return $denied;
        }
        $format = strtolower((string) $request->get('format', 'json'));
        $run->load(['results', 'predictions', 'dataset', 'model', 'promptVersion']);
        $this->audit->log('AI_EVALUATION_EXPORTED', $run, $run->id, ['format' => $format], $request->user());
        $name = "ai-evaluation-run-{$run->id}";
        if ($format === 'csv') {
            return response($this->report->toCsv($run), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
        }
        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.ai-evaluation-pdf', ['report' => $this->reportBody($run)]);

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
        }

        return response()->json($this->reportBody($run), 200, ['Content-Disposition' => "attachment; filename=\"{$name}.json\""]);
    }

    // ------------------------------------------------------------------ human ratings

    /** POST /api/ai/evaluation/ratings — faculty rating of a rubric / generated question (subjective signal) */
    public function rate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rateable_type' => ['required', Rule::in(['rubric', 'generated_question'])], 'rateable_id' => ['required', 'integer'],
            'dimension_scores' => ['required', 'array', 'min:1'], 'dimension_scores.*' => ['integer', 'min:1', 'max:5'],
            'decision' => ['nullable', Rule::in(AiEvaluationRating::DECISIONS)], 'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $courseId = null;
        if ($data['rateable_type'] === 'rubric') {
            $r = \App\Models\Rubric::with('assessment.course')->find($data['rateable_id']);
            $courseId = $r?->assessment?->course_id;
            if (!$r || !$request->user()->can('view', $r)) {
                return $this->error('Unauthorized access to rubric.', 403);
            }
        } else {
            $g = \App\Models\GeneratedQuestion::with('request.course')->find($data['rateable_id']);
            $courseId = $g?->request?->course_id;
            if (!$g || !$g->request?->course || !app(\App\Services\CourseAccessService::class)->can($request->user(), $g->request->course, 'view')) {
                return $this->error('Unauthorized access to generated question.', 403);
            }
        }
        $rating = AiEvaluationRating::updateOrCreate(['rateable_type' => $data['rateable_type'], 'rateable_id' => $data['rateable_id'], 'user_id' => $request->user()->id], [
            'course_id' => $courseId, 'dimension_scores' => $data['dimension_scores'], 'overall_score' => round(array_sum($data['dimension_scores']) / count($data['dimension_scores']), 2),
            'decision' => $data['decision'] ?? null, 'comment' => $data['comment'] ?? null]);

        return $this->ok('Rating saved.', $rating, 201);
    }

    // ------------------------------------------------------------------ payloads

    protected function reportBody(AiEvaluationRun $run): array
    {
        $run->loadMissing(['results', 'dataset', 'model', 'promptVersion', 'creator:id,name']);
        $summary = $run->summary ?? [];

        return [
            'generated_at' => now()->toIso8601String(),
            'executive_summary' => ['task' => $run->task, 'status' => $run->status, 'gate_status' => $run->gate_status, 'headline_metric' => $summary['headline_metric'] ?? null, 'headline_value' => $summary['headline_value'] ?? null,
                'example_count' => $run->example_count, 'size_category' => $summary['size_category'] ?? null, 'warnings' => $summary['warnings'] ?? [], 'regression' => $summary['regression'] ?? null],
            'model_information' => $run->model ? ['model' => $run->model->model_name, 'provider' => $run->model->provider, 'type' => $run->model->model_type, 'task' => $run->model->task, 'version' => $run->model->version, 'configuration' => $run->model->configuration] : null,
            'prompt_version' => $run->promptVersion ? ['feature' => $run->promptVersion->feature, 'version' => $run->promptVersion->version, 'prompt_hash' => $run->promptVersion->prompt_hash] : null,
            'dataset_information' => $run->dataset ? ['name' => $run->dataset->name, 'version' => $run->dataset->version, 'source' => $run->dataset->source, 'split' => $run->dataset->split, 'examples' => $run->example_count] : null,
            'methodology' => $this->methodology($run->task),
            'metrics' => $this->metricsPayload($run),
            'quality_gates' => $summary['gates'] ?? [],
            'error_analysis' => $summary['error_breakdown'] ?? [],
            'known_limitations' => $summary['limitations'] ?? $this->report->limitations($run->task, $run->example_count),
            'recommendations' => $this->recommendations($run),
            'run' => $this->runPayload($run),
        ];
    }

    protected function methodology(string $task): string
    {
        return match ($task) {
            'QUESTION_CLASSIFICATION', 'DIFFICULTY_CLASSIFICATION', 'BLOOM_CLASSIFICATION' => 'Batch inference through the production STEP 10 analyzers; predictions compared with faculty-validated labels; accuracy, per-class precision/recall/F1, macro/weighted F1 and a confusion matrix are reported. Macro F1 is emphasised for imbalanced classes.',
            'LO_ALIGNMENT' => 'MiniLM embeddings + cosine similarity at the production STEP 11 thresholds (strong ≥ 0.70, weak ≥ 0.50) compared with faculty-validated alignment labels.',
            'SIMILARITY' => 'MiniLM embeddings + cosine similarity classified at the production STEP 12 thresholds (0.85 / 0.70 / 0.50); duplicate-detection precision/recall/F1 plus an evaluation-only threshold sweep. Production thresholds are not modified.',
            'RUBRIC_GENERATION' => 'Rubrics are generated with the production STEP 25 engine; marks validity (sum of criteria = total) is checked automatically and faculty 1–5 ratings/decisions are aggregated.',
            'GRADING_ASSISTANCE' => 'AI suggested marks (recorded or live STEP 27) compared with finalized faculty marks: MAE, RMSE, MAPE, mean signed error, exact/±0.5/±1/±2 agreement, and grouped error analysis by question type/difficulty/Bloom level.',
            'ANSWER_RUBRIC_ALIGNMENT' => 'Criterion-level AI alignment statuses (recorded or live STEP 28) compared with faculty-reviewed statuses: criterion precision/recall/F1, false positives/negatives and overall agreement.',
            'DOCUMENT_CHAT' => 'Each question is answered by the production STEP 32 pipeline over the example documents; answer support (keyword coverage), citation coverage/accuracy, refusal behaviour on unanswerable questions and prompt-injection leakage are measured.',
            'QUESTION_GENERATION' => 'Drafts are generated by the production STEP 33 pipeline; constraint satisfaction is the share of drafts with no failed constraint (topic, type, difficulty, Bloom, CO alignment, similarity, marks).',
            default => 'Task-specific evaluation.',
        };
    }

    protected function recommendations(AiEvaluationRun $run): array
    {
        $out = ['Review example-level errors before drawing conclusions; metrics are engineering signals, not academic validity.'];
        foreach ($run->summary['gates'] ?? [] as $g) {
            if ($g['passed'] === false) {
                $out[] = "Investigate '{$g['metric']}' (" . $g['value'] . ') which did not meet its configured gate; do not deploy changes automatically.';
            }
        }
        if ($run->summary['regression']['regression'] ?? false) {
            $out[] = 'Performance regression detected versus the previous run — compare runs before accepting any model/prompt change.';
        }
        if (($run->summary['size_category'] ?? '') === 'VERY_LIMITED' || ($run->summary['size_category'] ?? '') === 'LIMITED') {
            $out[] = 'Grow the faculty-validated dataset before treating results as representative.';
        }

        return $out;
    }

    protected function metricsPayload(AiEvaluationRun $run): array
    {
        $scalars = [];
        $structured = [];
        foreach ($run->results as $r) {
            if ($r->metric_value !== null) {
                $scalars[$r->metric_name] = (float) $r->metric_value;
            }
            if ($r->metric_metadata !== null) {
                $structured[$r->metric_name] = $r->metric_metadata;
            }
        }

        return ['task' => $run->task, 'headline_metric' => EvaluationReportService::headlineMetrics()[$run->task] ?? null, 'scalars' => $scalars, 'structured' => $structured, 'gates' => $run->summary['gates'] ?? [], 'regression' => $run->summary['regression'] ?? null];
    }

    protected function runPayload(AiEvaluationRun $run): array
    {
        return $this->report->runHeader($run) + ['processed_count' => $run->processed_count, 'inference_ms' => $run->inference_ms, 'configuration' => $run->configuration, 'summary' => $run->summary,
            'failure_reason' => $run->failure_reason, 'started_at' => $run->started_at?->toIso8601String(), 'created_at' => $run->created_at?->toIso8601String(), 'created_by' => $run->created_by];
    }

    protected function datasetPayload(AiEvaluationDataset $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'description' => $d->description, 'task' => $d->task, 'version' => $d->version, 'source' => $d->source, 'split' => $d->split, 'status' => $d->status,
            'course_id' => $d->course_id, 'created_by' => $d->relationLoaded('creator') && $d->creator ? ['id' => $d->creator->id, 'name' => $d->creator->name] : null,
            'examples_count' => $d->examples_count ?? null, 'runs_count' => $d->runs_count ?? null, 'validation_report' => $d->validation_report, 'validated_at' => $d->validated_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(), 'updated_at' => $d->updated_at?->toIso8601String()];
    }

    /** Student answers/document bodies are trimmed so example-level views don't spill raw private text. */
    protected function safeInput(array $input): array
    {
        foreach (['answer', 'documents', 'document_context'] as $k) {
            if (isset($input[$k])) {
                $input[$k] = is_string($input[$k]) ? mb_substr($input[$k], 0, 160) . (mb_strlen($input[$k]) > 160 ? '…' : '') : '[' . count((array) $input[$k]) . ' item(s) omitted]';
            }
        }

        return $input;
    }

    protected function denyDataset(Request $request, AiEvaluationDataset $dataset): ?JsonResponse
    {
        return $this->service->canAccessDataset($request->user(), $dataset) ? null : $this->error('Unauthorized access to evaluation dataset.', 403);
    }

    protected function denyRun(Request $request, AiEvaluationRun $run): ?JsonResponse
    {
        return $this->service->canAccessRun($request->user(), $run) ? null : $this->error('Unauthorized access to evaluation run.', 403);
    }

    protected function ok(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status, mixed $data = null): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message, 'data' => $data], $status);
    }
}
