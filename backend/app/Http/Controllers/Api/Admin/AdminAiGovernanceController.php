<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiEvaluationRun;
use App\Models\AiModel;
use App\Models\HealthCheckResult;
use App\Services\Admin\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI oversight built only from recorded data: the model registry (ai_models), completed evaluation runs with their
 * stored metrics, faculty review/override decisions on AI results, and live service metadata. No accuracy figure is
 * ever derived from confidence scores or request success rates.
 */
class AdminAiGovernanceController extends Controller
{
    public function __construct(protected FeatureFlagService $features, protected \App\Services\AiService $ai) {}

    public function index(): JsonResponse
    {
        $latestAi = HealthCheckResult::query()->where('service', 'ai_service')->latest('checked_at')->first();

        $models = AiModel::query()->withCount('evaluationRuns')->orderBy('task')->orderBy('model_name')->get()->map(function (AiModel $m) {
            $last = AiEvaluationRun::query()->where('model_id', $m->id)->where('status', AiEvaluationRun::STATUS_COMPLETED)->latest('completed_at')->first();

            return [
                'id' => $m->id,
                'model_name' => $m->model_name,
                'provider' => $m->provider,
                'model_type' => $m->model_type,
                'task' => $m->task,
                'version' => $m->version,
                'is_active' => $m->is_active,
                'configuration' => $this->safeConfig((array) $m->configuration),
                'evaluation_runs' => (int) $m->evaluation_runs_count,
                'last_evaluation' => $last ? [
                    'id' => $last->id,
                    'completed_at' => $last->completed_at?->toIso8601String(),
                    'gate_status' => $last->gate_status,
                    'headline_metric' => $last->summary['headline_metric'] ?? null,
                    'headline_value' => $last->summary['headline_value'] ?? null,
                ] : null,
                'registered_at' => $m->created_at?->toIso8601String(),
            ];
        });

        $runs = AiEvaluationRun::query()->with(['dataset:id,name,version,task', 'model:id,model_name,version'])->latest('id')->limit(50)->get()
            ->map(fn (AiEvaluationRun $r) => [
                'id' => $r->id,
                'task' => $r->task,
                'status' => $r->status,
                'gate_status' => $r->gate_status,
                'model' => $r->model?->only(['id', 'model_name', 'version']),
                'dataset' => $r->dataset ? ['id' => $r->dataset->id, 'name' => $r->dataset->name, 'version' => $r->dataset->version] : null,
                'examples' => $r->example_count,
                'processed' => $r->processed_count,
                'inference_ms' => $r->inference_ms,
                'headline_metric' => $r->summary['headline_metric'] ?? null,
                'headline_value' => $r->summary['headline_value'] ?? null,
                'metrics' => $this->pickMetrics((array) ($r->summary['metrics'] ?? [])),
                'regression' => $r->summary['regression']['regression'] ?? null,
                'size_category' => $r->summary['size_category'] ?? null,
                'failure_reason' => $r->status === AiEvaluationRun::STATUS_FAILED ? mb_substr((string) $r->failure_reason, 0, 200) : null,
                'completed_at' => $r->completed_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $reviews = Schema::hasTable('ai_result_reviews')
            ? DB::table('ai_result_reviews')->where('created_at', '>=', now()->subDays(90))
                ->select('ai_result_type', 'action', DB::raw('COUNT(*) as n'))->groupBy('ai_result_type', 'action')->get()
                ->groupBy('ai_result_type')->map(function ($rows, $type) {
                    $by = $rows->pluck('n', 'action')->map(fn ($n) => (int) $n);
                    $total = $by->sum();

                    return [
                        'type' => $type,
                        'total' => $total,
                        'accepted' => (int) ($by['ACCEPTED'] ?? 0),
                        'rejected' => (int) ($by['REJECTED'] ?? 0),
                        'overridden' => (int) ($by['OVERRIDDEN'] ?? 0),
                        'reviewed' => (int) ($by['REVIEWED'] ?? 0),
                        'override_rate' => $total ? round((($by['OVERRIDDEN'] ?? 0) + ($by['REJECTED'] ?? 0)) / $total * 100, 1) : null,
                    ];
                })->values()
            : collect();

        $overrideReasons = Schema::hasTable('ai_result_reviews')
            ? DB::table('ai_result_reviews')->where('created_at', '>=', now()->subDays(90))->whereNotNull('override_reason')
                ->select('override_reason', DB::raw('COUNT(*) as n'))->groupBy('override_reason')->orderByDesc('n')->limit(10)->get()
                ->map(fn ($r) => ['reason' => $r->override_reason, 'count' => (int) $r->n])
            : collect();

        $aiFlags = collect($this->features->describe())->filter(fn ($f) => in_array($f['key'], ['ai_analysis', 'academic_chat', 'question_generation', 'ai_grading', 'ai_translation', 'ai_ocr', 'ai_transcription'], true))->values();

        // Live provider/model status from the AI service (booleans and model names only; never keys or URLs)
        try {
            $toolkit = $this->ai->toolkitStatus();
            $toolkit = ['available' => true, 'generation' => $toolkit['generation'] ?? null, 'tools' => $toolkit['tools'] ?? [], 'memory' => $toolkit['memory'] ?? null];
        } catch (\Throwable $e) {
            $toolkit = ['available' => false, 'generation' => null, 'tools' => [], 'error' => 'The AI service did not answer the status request.'];
        }
        $toolJobs = \App\Models\AiToolJob::query()->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('tool, status, COUNT(*) as n')->groupBy('tool', 'status')->get()
            ->groupBy('tool')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n));

        return response()->json([
            'status' => 'success',
            'data' => [
                'service' => [
                    'status' => $latestAi?->status ?? 'unknown',
                    'checked_at' => $latestAi?->checked_at?->toIso8601String(),
                    'model' => $latestAi?->detail['model'] ?? null,
                    'model_loaded' => $latestAi?->detail['model_loaded'] ?? null,
                    'response_ms' => $latestAi?->response_ms,
                ],
                'configuration' => [
                    'service_url_configured' => trim((string) config('services.ai.url')) !== '',
                    'api_key_configured' => trim((string) config('services.ai.api_key')) !== '',
                    'request_timeout_seconds' => (int) config('services.ai.timeout'),
                    'connect_timeout_seconds' => (int) config('services.ai.connect_timeout'),
                    'feature_flags' => $aiFlags,
                    'change_note' => 'Model identifiers and timeouts are deployment configuration (backend/.env, ai-service/.env). Change them through a deployment, then run a health check and an evaluation run.',
                ],
                'provider' => [
                    'selected' => $this->ai->provider(),
                    'toolkit' => $toolkit,
                    'tool_jobs_30d' => $toolJobs,
                ],
                'models' => $models,
                'evaluations' => $runs,
                'evaluation_totals' => [
                    'completed' => AiEvaluationRun::query()->where('status', AiEvaluationRun::STATUS_COMPLETED)->count(),
                    'failed' => AiEvaluationRun::query()->where('status', AiEvaluationRun::STATUS_FAILED)->count(),
                    'gate_failed' => AiEvaluationRun::query()->where('gate_status', AiEvaluationRun::GATE_FAILED)->count(),
                ],
                'reviews_90d' => $reviews,
                'override_reasons_90d' => $overrideReasons,
                'principle' => 'AI output is advisory. It never approves assessments, finalizes grades or changes official records.',
            ],
        ]);
    }

    /** Headline classification metrics only, when the run recorded them. */
    protected function pickMetrics(array $metrics): array
    {
        $out = [];
        foreach (['accuracy', 'macro_precision', 'macro_recall', 'macro_f1', 'weighted_f1', 'mae', 'pearson', 'spearman'] as $k) {
            if (isset($metrics[$k]) && is_numeric($metrics[$k])) {
                $out[$k] = (float) $metrics[$k];
            }
        }

        return $out;
    }

    /** Drop anything that looks like a credential from stored model configuration. */
    protected function safeConfig(array $config): array
    {
        return collect($config)->reject(fn ($v, $k) => preg_match('/key|token|secret|password/i', (string) $k))
            ->map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v))
            ->all();
    }
}
