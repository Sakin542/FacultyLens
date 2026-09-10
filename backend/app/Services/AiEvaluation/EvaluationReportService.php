<?php

namespace App\Services\AiEvaluation;

use App\Models\AiEvaluationRun;

/**
 * STEP 35: turns raw metrics into an evaluation report — quality gates, regression vs the previous completed run
 * on the same task, dataset-size category, limitations and run comparison. Reports never change production settings.
 */
class EvaluationReportService
{
    /** Metric name → true when lower is better. */
    public const LOWER_IS_BETTER = ['mae' => true, 'rmse' => true, 'mape' => true, 'unsupported_answer_rate' => true, 'injection_leak_rate' => true, 'false_refusal_rate' => true, 'rejection_rate' => true, 'inference_failures' => true, 'invalid_rubric_count' => true, 'failed' => true];

    public static function headlineMetrics(): array
    {
        return [
            'QUESTION_CLASSIFICATION' => 'macro_f1', 'DIFFICULTY_CLASSIFICATION' => 'macro_f1', 'BLOOM_CLASSIFICATION' => 'macro_f1', 'LO_ALIGNMENT' => 'macro_f1',
            'SIMILARITY' => 'duplicate_f1', 'RUBRIC_GENERATION' => 'marks_validity_rate', 'GRADING_ASSISTANCE' => 'mae', 'ANSWER_RUBRIC_ALIGNMENT' => 'macro_f1',
            'DOCUMENT_CHAT' => 'citation_accuracy', 'QUESTION_GENERATION' => 'constraint_satisfaction_rate',
        ];
    }

    public function sizeCategory(int $n): string
    {
        foreach ((array) config('ai_evaluation.size_categories') as $c) {
            if ($n <= $c['max']) {
                return $c['label'];
            }
        }

        return 'LARGE';
    }

    /**
     * @param array<string, float|null> $metrics
     * @return array{status:string, gates:array, warnings:string[]}
     */
    public function evaluateGates(string $task, array $metrics, int $exampleCount): array
    {
        $gates = [];
        $failed = false;
        foreach ((array) config("ai_evaluation.quality_gates.{$task}", []) as $metric => $rule) {
            $value = $metrics[$metric] ?? null;
            $pass = null;
            if ($value !== null) {
                $pass = isset($rule['min']) ? $value >= $rule['min'] : (isset($rule['max']) ? $value <= $rule['max'] : true);
            }
            $gates[] = ['metric' => $metric, 'value' => $value, 'min' => $rule['min'] ?? null, 'max' => $rule['max'] ?? null, 'passed' => $pass];
            if ($pass === false) {
                $failed = true;
            }
        }
        $warnings = [];
        $minWarn = (int) config('ai_evaluation.min_examples_warning', 30);
        if ($exampleCount < $minWarn) {
            $warnings[] = "Small evaluation dataset ({$exampleCount} examples). Results should be interpreted cautiously.";
        }
        if (($metrics['inference_failures'] ?? 0) > 0) {
            $warnings[] = (int) $metrics['inference_failures'] . ' example(s) could not be scored because inference failed.';
        }
        foreach ($gates as $g) {
            if ($g['passed'] === null) {
                $warnings[] = "Gate metric '{$g['metric']}' was not produced by this run.";
            }
        }
        $status = $failed ? AiEvaluationRun::GATE_FAILED : ($warnings ? AiEvaluationRun::GATE_WARNINGS : AiEvaluationRun::GATE_PASSED);
        foreach ($gates as $g) {
            if ($g['passed'] === false) {
                $warnings[] = ucfirst(str_replace('_', ' ', $g['metric'])) . ' ' . (isset($g['min']) ? "({$g['value']}) below configured target {$g['min']}" : "({$g['value']}) above configured maximum {$g['max']}") . '.';
            }
        }

        return ['status' => $status, 'gates' => $gates, 'warnings' => $warnings];
    }

    /**
     * Compare a run's headline metric with the previous completed run on the same task (any dataset owned by the same user).
     *
     * @return array{previous_run_id:int|null, metric:string, current:float|null, previous:float|null, delta:float|null, regression:bool}
     */
    public function regression(AiEvaluationRun $run, array $metrics): array
    {
        $metric = self::headlineMetrics()[$run->task] ?? 'macro_f1';
        $previous = AiEvaluationRun::where('task', $run->task)->where('status', AiEvaluationRun::STATUS_COMPLETED)
            ->where('id', '<', $run->id)->where('created_by', $run->created_by)->orderByDesc('id')->with('results')->first();
        $current = $metrics[$metric] ?? null;
        $prev = $previous ? ($previous->metricMap()[$metric] ?? null) : null;
        $delta = ($current !== null && $prev !== null) ? round($current - $prev, 4) : null;
        $lower = self::LOWER_IS_BETTER[$metric] ?? false;
        $regression = $delta !== null && ($lower ? $delta > 0.005 : $delta < -0.005);

        return ['previous_run_id' => $previous?->id, 'metric' => $metric, 'current' => $current, 'previous' => $prev, 'delta' => $delta, 'regression' => $regression, 'lower_is_better' => $lower];
    }

    /**
     * Side-by-side comparison of two runs (same or different models/prompts).
     */
    public function compare(AiEvaluationRun $a, AiEvaluationRun $b): array
    {
        $ma = $a->metricMap();
        $mb = $b->metricMap();
        $rows = [];
        foreach (array_unique(array_merge(array_keys($ma), array_keys($mb))) as $name) {
            if (!isset($ma[$name]) && !isset($mb[$name])) {
                continue;
            }
            $lower = self::LOWER_IS_BETTER[$name] ?? false;
            $delta = isset($ma[$name], $mb[$name]) ? round($mb[$name] - $ma[$name], 4) : null;
            $rows[] = ['metric' => $name, 'run_a' => $ma[$name] ?? null, 'run_b' => $mb[$name] ?? null, 'delta' => $delta,
                'direction' => $delta === null || abs($delta) < 0.0005 ? 'unchanged' : (($lower ? $delta < 0 : $delta > 0) ? 'improved' : 'degraded')];
        }
        $headline = self::headlineMetrics()[$a->task] ?? null;

        return [
            'run_a' => $this->runHeader($a), 'run_b' => $this->runHeader($b), 'same_task' => $a->task === $b->task, 'headline_metric' => $headline, 'rows' => $rows,
            'note' => 'Comparison is informational. No model is selected or promoted automatically.',
        ];
    }

    public function runHeader(AiEvaluationRun $run): array
    {
        $run->loadMissing(['model', 'promptVersion', 'dataset']);

        return ['id' => $run->id, 'task' => $run->task, 'status' => $run->status, 'gate_status' => $run->gate_status, 'dataset' => $run->dataset ? ['id' => $run->dataset->id, 'name' => $run->dataset->name, 'version' => $run->dataset->version] : null,
            'model' => $run->model ? ['id' => $run->model->id, 'name' => $run->model->model_name, 'version' => $run->model->version, 'type' => $run->model->model_type] : null,
            'prompt_version' => $run->promptVersion ? ['feature' => $run->promptVersion->feature, 'version' => $run->promptVersion->version] : null,
            'example_count' => $run->example_count, 'completed_at' => $run->completed_at?->toIso8601String()];
    }

    public function limitations(string $task, int $n): array
    {
        $l = (array) config('ai_evaluation.limitations');
        $l[] = 'Dataset size category: ' . $this->sizeCategory($n) . " ({$n} examples) — a reporting category, not a statistical sufficiency claim.";
        if ($task === 'GRADING_ASSISTANCE') {
            $l[] = 'Faculty-vs-faculty disagreement (STEP 29) should be read alongside AI-vs-faculty error; neither proves correctness.';
        }
        if (in_array($task, ['DOCUMENT_CHAT', 'QUESTION_GENERATION', 'RUBRIC_GENERATION'], true)) {
            $l[] = 'Keyword/constraint checks are proxies; faculty judgement remains authoritative for generative quality.';
        }

        return $l;
    }

    /** Build the CSV body for a run: metrics then predictions (no raw student answers). */
    public function toCsv(AiEvaluationRun $run): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['section', 'metric_or_example', 'value', 'is_correct', 'error_type', 'expected', 'predicted']);
        foreach ($run->results as $r) {
            fputcsv($out, ['metric', $r->metric_name, $r->metric_value, '', '', '', $r->metric_metadata ? json_encode($r->metric_metadata) : '']);
        }
        foreach ($run->predictions as $p) {
            $pred = $p->prediction ?? [];
            unset($pred['answer'], $pred['drafts'], $pred['criteria']);
            fputcsv($out, ['prediction', $p->example_id, $p->score, $p->is_correct === null ? '' : ($p->is_correct ? 1 : 0), $p->error_type ?? '', json_encode($p->expected_output), json_encode($pred)]);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }
}
