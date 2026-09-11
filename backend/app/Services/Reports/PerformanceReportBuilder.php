<?php

namespace App\Services\Reports;

use App\Models\LearningOutcomePerformanceResult;
use App\Models\StudentSubmission;
use App\Services\Analytics\AnalyticsScopeService;
use App\Services\Analytics\PerformanceAnalyticsService;
use App\Services\StudentPerformanceService;

/**
 * Student Performance Report — STEP 30 aggregates from finalized faculty grades only.
 * Never includes student names, identifiers, e-mails or individual answers.
 */
class PerformanceReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected PerformanceAnalyticsService $performance) {}

    public function build(ReportContext $ctx): array
    {
        $ids = $ctx->studentDataAssessmentIds;
        $summary = $this->performance->summary($ids);
        $trend = $this->performance->trend($ids);
        $gaps = $this->performance->gaps($ids);
        $questions = $this->performance->questions($ids, 'number');
        $topics = $this->performance->topics($ids);
        $students = $ids === [] ? 0 : (int) StudentSubmission::whereIn('assessment_id', $ids)->whereIn('grading_status', (array) config('performance.finalized_grading_statuses'))->distinct('student_id')->count('student_id');

        $warnings = [];
        if (! $summary['available']) {
            $warnings[] = 'No finalized faculty grades exist for the selected scope.';
        }
        if ($ctx->assessmentIds !== [] && count($ids) < count($ctx->assessmentIds)) {
            $warnings[] = (count($ctx->assessmentIds) - count($ids)).' assessment(s) are excluded because you are not authorized to view their student data.';
        }
        if ($summary['available'] && $gaps['analyzed_assessments'] === 0) {
            $warnings[] = 'Finalized grades exist but no STEP 30 performance analysis has been run; question, topic and outcome breakdowns are unavailable.';
        }

        $head = [
            $this->kv('Assessments in Scope', count($ids)),
            $this->kv('Student Count', $students),
            $this->kv('Finalized Submissions', $summary['submissions']),
            $this->kv('Response Count', $summary['responses']),
            $this->kv('Average %', $this->na($summary['average_percentage'], '%', 'No data')),
            $this->kv('Median %', $this->na($summary['median_percentage'], '%', 'No data')),
            $this->kv('Minimum %', $this->na($summary['minimum_percentage'], '%', 'No data')),
            $this->kv('Maximum %', $this->na($summary['maximum_percentage'], '%', 'No data')),
            $this->kv('Benchmark %', $summary['benchmark_percent']),
            $this->kv('Status', $summary['status']),
        ];

        $co = $this->coPerformance($ids);
        $stats = $summary['available'] ? array_map(fn ($k, $label) => ['metric' => $label, 'value' => $summary[$k]], array_keys($this->statLabels()), $this->statLabels()) : [];

        $doc = $this->document($ctx, $head, [$this->section('privacy', 'Privacy', [], config('institutional_reports.sensitive_footer'))], [
            $this->table('statistics', 'Performance Statistics (finalized grades)', ['metric' => 'Metric', 'value' => 'Value'], $stats),
            $this->table('assessments', 'Assessment Performance', ['title' => 'Assessment', 'type' => 'Type', 'date' => 'Date', 'average_percentage' => 'Average %', 'responses' => 'Responses', 'status' => 'Status'], $trend),
            $this->table('questions', 'Question Performance', ['assessment_title' => 'Assessment', 'question_number' => 'Question', 'co' => 'CO', 'difficulty' => 'Difficulty', 'cognitive_level' => 'Cognitive Level', 'average_percentage' => 'Average %', 'responses' => 'Responses', 'gap' => 'Gap', 'status' => 'Status'], $questions),
            $this->table('topics', 'Topic Performance', ['topic' => 'Topic', 'questions' => 'Questions', 'assessments' => 'Assessments', 'responses' => 'Responses', 'average_percentage' => 'Average %', 'gap' => 'Gap', 'status' => 'Status'], $topics),
            $this->table('co_performance', 'CO Performance', ['code' => 'CO', 'description' => 'Description', 'assessments' => 'Assessments', 'responses' => 'Responses', 'average_percentage' => 'Average %', 'gap' => 'Gap', 'status' => 'Status'], $co),
            $this->table('learning_gaps', 'Learning Gaps', ['code' => 'CO', 'assessment_title' => 'Assessment', 'average_percentage' => 'Average %', 'benchmark_percent' => 'Benchmark %', 'gap' => 'Gap', 'responses' => 'Responses', 'status' => 'Status'], $gaps['top_gaps']),
        ], $warnings, $trend ? max(array_filter(array_column($trend, 'date'))) ?: null : null);
        $doc['has_data'] = (bool) $summary['available'];

        return $doc;
    }

    /** @return array<string, string> */
    protected function statLabels(): array
    {
        return ['average_percentage' => 'Average %', 'median_percentage' => 'Median %', 'minimum_percentage' => 'Minimum %', 'maximum_percentage' => 'Maximum %', 'submissions' => 'Finalized Submissions', 'responses' => 'Responses', 'benchmark_percent' => 'Benchmark %', 'gap' => 'Gap vs Benchmark', 'status' => 'Status'];
    }

    /** Outcome performance aggregated (response-weighted) across the current STEP 30 runs in scope. */
    protected function coPerformance(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }
        $runIds = app(AnalyticsScopeService::class)->currentPerformanceRunsQuery($assessmentIds)->pluck('id');
        if ($runIds->isEmpty()) {
            return [];
        }
        $svc = app(StudentPerformanceService::class);
        $by = [];
        foreach (LearningOutcomePerformanceResult::whereIn('performance_analysis_run_id', $runIds)->get() as $r) {
            $k = $r->learning_outcome_id ?? $r->lo_code;
            $by[$k] ??= ['code' => $r->lo_code, 'description' => mb_substr((string) $r->lo_description, 0, 120), 'assessments' => 0, 'responses' => 0, 'w' => 0.0, 'n' => 0];
            $by[$k]['assessments']++;
            $by[$k]['responses'] += (int) $r->response_count;
            if ($r->average_percentage !== null && (int) $r->response_count > 0) {
                $by[$k]['w'] += (float) $r->average_percentage * (int) $r->response_count;
                $by[$k]['n'] += (int) $r->response_count;
            }
        }

        return array_values(array_map(function ($c) use ($svc) {
            $avg = $c['n'] > 0 ? round($c['w'] / $c['n'], 2) : null;

            return ['code' => $c['code'], 'description' => $c['description'], 'assessments' => $c['assessments'], 'responses' => $c['responses'], 'average_percentage' => $avg,
                'gap' => $avg !== null ? round($svc->gap($avg), 2) : null, 'status' => $svc->classify($avg, $c['responses'])];
        }, $by));
    }
}
