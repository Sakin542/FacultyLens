<?php

namespace App\Services\Reports;

use App\Models\AiEvaluationRun;
use App\Services\AiEvaluation\EvaluationReportService;
use App\Services\AiEvaluationService;

/**
 * AI Evaluation Report — STEP 35 runs visible to the user (own datasets; admins see all) with model,
 * prompt and dataset versions and every recorded metric. Missing metrics read "Not evaluated", never 0.
 */
class AiEvaluationReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AiEvaluationService $evaluation) {}

    public function build(ReportContext $ctx): array
    {
        $overview = $this->evaluation->overview($ctx->user);
        $runs = $this->evaluation->visibleRuns($ctx->user)->where('status', AiEvaluationRun::STATUS_COMPLETED)
            ->with(['dataset:id,name,version,split,status', 'model:id,model_name,version,provider', 'promptVersion:id,feature,version', 'results'])
            ->orderByDesc('completed_at')->limit(200)->get();
        $headline = EvaluationReportService::headlineMetrics();

        $taskRows = array_map(fn ($t) => ['task' => $t['task'], 'evaluated' => $t['evaluated'] ? 'Yes' : 'No', 'headline_metric' => $t['headline_metric'], 'headline_value' => $t['evaluated'] ? $t['headline_value'] : 'Not evaluated',
            'gate_status' => $t['gate_status'] ?? 'Not evaluated', 'run_id' => $t['run']['id'] ?? null, 'completed_at' => $t['run']['completed_at'] ?? null, 'examples' => $t['run']['example_count'] ?? null, 'regression' => $t['evaluated'] ? ($t['regression'] ? 'Yes' : 'No') : null], $overview['tasks']);

        $runRows = $runs->map(fn ($r) => ['run_id' => $r->id, 'task' => $r->task, 'model' => $r->model?->model_name, 'model_version' => $r->model?->version, 'prompt_version' => $r->promptVersion ? $r->promptVersion->feature.' '.$r->promptVersion->version : null,
            'dataset' => $r->dataset?->name, 'dataset_version' => $r->dataset?->version, 'examples' => $r->example_count, 'gate_status' => $r->gate_status, 'headline_metric' => $r->summary['headline_metric'] ?? ($headline[$r->task] ?? null),
            'headline_value' => $r->summary['headline_value'] ?? 'Not evaluated', 'evaluation_date' => $r->completed_at?->toDateTimeString()])->values()->all();

        $metricRows = [];
        foreach ($runs as $r) {
            foreach ($r->results as $m) {
                $metricRows[] = ['run_id' => $r->id, 'task' => $r->task, 'model' => $r->model?->model_name, 'model_version' => $r->model?->version, 'prompt_version' => $r->promptVersion?->version, 'dataset' => $r->dataset?->name,
                    'metric' => $m->metric_name, 'score' => $m->metric_value === null ? 'Not evaluated' : round((float) $m->metric_value, 3), 'evaluation_date' => $r->completed_at?->toDateTimeString()];
            }
        }

        $summary = [
            $this->kv('Overall Evaluation Status', $overview['overall_status']),
            $this->kv('Evaluated Tasks', count(array_filter($overview['tasks'], fn ($t) => $t['evaluated'])).' / '.count($overview['tasks'])),
            $this->kv('Completed Runs', $runs->count()),
            $this->kv('Datasets', $overview['dataset_count']),
        ];
        $warnings = $runs->isEmpty() ? ['No completed AI evaluation runs are visible to you. Metrics are reported as "Not evaluated".'] : [];
        foreach ((array) ($overview['limitations'] ?? []) as $l) {
            $warnings[] = is_array($l) ? implode(' ', $l) : (string) $l;
        }

        return $this->document($ctx, $summary, [$this->section('note', 'Interpretation', [], 'Metrics come from faculty-validated evaluation datasets and describe the assistant, not students or faculty. Tasks without a completed run are reported as Not evaluated.')], [
            $this->table('tasks', 'Evaluation Status by Task', ['task' => 'Task', 'evaluated' => 'Evaluated', 'headline_metric' => 'Headline Metric', 'headline_value' => 'Value', 'gate_status' => 'Gate', 'run_id' => 'Run', 'examples' => 'Examples', 'completed_at' => 'Evaluation Date', 'regression' => 'Regression'], $taskRows),
            $this->table('runs', 'Evaluation Runs', ['run_id' => 'Run', 'task' => 'Task', 'model' => 'Model', 'model_version' => 'Model Version', 'prompt_version' => 'Prompt Version', 'dataset' => 'Dataset', 'dataset_version' => 'Dataset Version', 'examples' => 'Examples', 'gate_status' => 'Gate', 'headline_metric' => 'Headline Metric', 'headline_value' => 'Value', 'evaluation_date' => 'Evaluation Date'], $runRows),
            $this->table('metrics', 'Metrics', ['run_id' => 'Run', 'task' => 'Task', 'model' => 'Model', 'model_version' => 'Model Version', 'prompt_version' => 'Prompt Version', 'dataset' => 'Dataset', 'metric' => 'Metric', 'score' => 'Score', 'evaluation_date' => 'Evaluation Date'], $metricRows),
        ], $warnings, $runs->max('completed_at')?->toISOString());
    }
}
