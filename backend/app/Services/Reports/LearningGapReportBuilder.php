<?php

namespace App\Services\Reports;

use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Services\Analytics\AnalyticsScopeService;
use App\Services\Analytics\PerformanceAnalyticsService;
use App\Services\StudentPerformanceService;

/**
 * Learning Gap Report — STEP 30 classifications (STRONG / ON_TARGET / MINOR_GAP / MODERATE_GAP / HIGH_GAP /
 * INSUFFICIENT_DATA). Insufficient data is always labelled as such and never counted as a gap.
 */
class LearningGapReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected PerformanceAnalyticsService $performance, protected AnalyticsScopeService $scope, protected StudentPerformanceService $svc) {}

    public function build(ReportContext $ctx): array
    {
        $ids = $ctx->studentDataAssessmentIds;
        $gaps = $this->performance->gaps($ids);
        $topics = $this->performance->topics($ids);
        $benchmark = $this->svc->expected();

        $runIds = $ids === [] ? collect() : $this->scope->currentPerformanceRunsQuery($ids)->pluck('id');
        $outcomeRows = $runIds->isEmpty() ? [] : LearningOutcomePerformanceResult::whereIn('performance_analysis_run_id', $runIds)
            ->join('performance_analysis_runs', 'performance_analysis_runs.id', '=', 'learning_outcome_performance_results.performance_analysis_run_id')
            ->join('assessments', 'assessments.id', '=', 'performance_analysis_runs.assessment_id')
            ->orderByRaw("CASE learning_outcome_performance_results.performance_status WHEN 'HIGH_GAP' THEN 0 WHEN 'MODERATE_GAP' THEN 1 WHEN 'MINOR_GAP' THEN 2 WHEN 'ON_TARGET' THEN 3 WHEN 'STRONG' THEN 4 ELSE 5 END")
            ->get(['learning_outcome_performance_results.*', 'assessments.title AS assessment_title'])
            ->map(fn ($r) => ['assessment' => $r->assessment_title, 'outcome' => $r->lo_code, 'description' => mb_substr((string) $r->lo_description, 0, 120), 'average_percentage' => $r->average_percentage !== null ? round((float) $r->average_percentage, 2) : null,
                'benchmark' => $benchmark, 'gap' => $r->performance_gap !== null ? round((float) $r->performance_gap, 2) : null, 'status' => $r->performance_status, 'responses' => (int) $r->response_count,
                'note' => $r->performance_status === PerformanceAnalysisRun::PERF_INSUFFICIENT ? 'Insufficient data — not a learning-gap finding' : null])->values()->all();

        $topicRows = array_map(fn ($t) => ['topic' => $t['topic'], 'average_percentage' => $t['average_percentage'], 'benchmark' => $benchmark, 'gap' => $t['gap'], 'status' => $t['status'], 'responses' => $t['responses'], 'questions' => $t['questions'],
            'note' => $t['status'] === PerformanceAnalysisRun::PERF_INSUFFICIENT ? 'Insufficient data — not a learning-gap finding' : null], $topics);

        $summary = [
            $this->kv('Assessments in Scope', count($ids)),
            $this->kv('Performance Analyses', $gaps['analyzed_assessments']),
            $this->kv('Benchmark %', $benchmark),
            $this->kv('Open Gaps (minor + moderate + high)', $gaps['analyzed_assessments'] ? $gaps['open_gaps'] : 'No analysis'),
        ];
        foreach ($gaps['counts'] as $status => $count) {
            $summary[] = $this->kv($this->humanize($status), $count);
        }
        $warnings = [];
        if ($gaps['analyzed_assessments'] === 0) {
            $warnings[] = 'No STEP 30 performance analysis exists for the selected scope.';
        }
        if ($ctx->assessmentIds !== [] && count($ids) < count($ctx->assessmentIds)) {
            $warnings[] = (count($ctx->assessmentIds) - count($ids)) . ' assessment(s) are excluded because you are not authorized to view their student data.';
        }
        $insufficient = (int) ($gaps['counts'][PerformanceAnalysisRun::PERF_INSUFFICIENT] ?? 0);
        if ($insufficient > 0) {
            $warnings[] = "{$insufficient} outcome result(s) have insufficient responses (fewer than " . $this->svc->minResponses() . ') and are reported as INSUFFICIENT_DATA, not as gaps.';
        }

        return $this->document($ctx, $summary, [$this->section('privacy', 'Privacy', [], config('institutional_reports.sensitive_footer'))], [
            $this->table('outcome_gaps', 'Learning Outcome Gaps', ['assessment' => 'Assessment', 'outcome' => 'Outcome', 'description' => 'Description', 'average_percentage' => 'Average %', 'benchmark' => 'Benchmark %', 'gap' => 'Gap', 'status' => 'Status', 'responses' => 'Responses', 'note' => 'Note'], $outcomeRows),
            $this->table('topic_gaps', 'Topic Gaps', ['topic' => 'Topic', 'average_percentage' => 'Average %', 'benchmark' => 'Benchmark %', 'gap' => 'Gap', 'status' => 'Status', 'responses' => 'Responses', 'questions' => 'Questions', 'note' => 'Note'], $topicRows),
            $this->table('status_counts', 'Classification Counts', ['status' => 'Status', 'outcomes' => 'Outcome Results'], $gaps['analyzed_assessments'] === 0 ? [] : array_map(fn ($s, $c) => ['status' => $s, 'outcomes' => $c], array_keys($gaps['counts']), $gaps['counts'])),
        ], $warnings);
    }
}
