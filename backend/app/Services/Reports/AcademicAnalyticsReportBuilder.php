<?php

namespace App\Services\Reports;

use App\Services\Analytics\AcademicAnalyticsService;

/**
 * Academic Analytics Report — export of the STEP 36 overview for an authorized scope. Reuses the
 * analytics service (no SQL aggregation here); tables mirror the dashboard sections.
 */
class AcademicAnalyticsReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AcademicAnalyticsService $analytics) {}

    public function build(ReportContext $ctx): array
    {
        $a = $this->analytics->overviewForScope($ctx->user, $ctx->filters, $ctx->courseIds, $ctx->assessmentIds);

        $summary = [];
        foreach ($a['kpis'] as $kpi) {
            $summary[] = $this->kv($kpi['label'], $kpi['value'] === null ? 'N/A' : $kpi['value'].(($kpi['unit'] ?? '') === 'percent' ? '%' : ''));
        }
        $summary[] = $this->kv('Student Data', $a['scope']['student_data_restricted'] ? 'Restricted for your role' : 'Aggregated');

        $kpiRows = [];
        foreach ($a['kpis'] as $k => $kpi) {
            $kpiRows[] = ['metric' => $kpi['label'], 'value' => $kpi['value'], 'unit' => $kpi['unit'] ?? null, 'basis' => $kpi['basis'] ?? null];
        }
        $perf = $a['performance'];
        $perfRows = [];
        foreach (['average_percentage' => 'Average %', 'median_percentage' => 'Median %', 'minimum_percentage' => 'Minimum %', 'maximum_percentage' => 'Maximum %', 'submissions' => 'Finalized Submissions', 'responses' => 'Responses', 'benchmark_percent' => 'Benchmark %', 'gap' => 'Gap', 'status' => 'Status'] as $k => $label) {
            $perfRows[] = ['metric' => $label, 'value' => $perf['available'] || in_array($k, ['benchmark_percent', 'status'], true) ? $perf[$k] : 'No finalized grades'];
        }
        $evalRows = array_map(fn ($t) => ['task' => $t['task'], 'headline_metric' => $t['headline_metric'], 'value' => $t['evaluated'] ? $t['headline_value'] : 'Not evaluated', 'gate_status' => $t['gate_status'] ?? 'Not evaluated', 'completed_at' => $t['completed_at']], $a['ai_evaluation']['tasks']);
        $rubrics = $a['rubrics'];
        $grading = $a['grading'];
        $collab = $a['collaboration'];

        $tables = [
            $this->table('kpis', 'KPI Summary', ['metric' => 'Metric', 'value' => 'Value', 'unit' => 'Unit', 'basis' => 'Basis'], $kpiRows),
            $this->table('assessments', 'Assessments', ['title' => 'Assessment', 'type' => 'Type', 'status' => 'Status', 'date' => 'Date', 'questions' => 'Questions', 'quality_score' => 'Quality', 'quality_rating' => 'Rating', 'performance_percentage' => 'Performance %', 'performance_status' => 'Performance Status', 'high_gaps' => 'High Gaps'],
                array_map(fn ($r) => $r + ['course_code' => $r['course']['code'] ?? null], $a['assessments'])),
            $this->table('quality', 'Assessment Quality', ['rating' => 'Rating', 'assessments' => 'Assessments'], array_map(fn ($r, $c) => ['rating' => $r, 'assessments' => $c], array_keys($a['assessment_quality']['counts']), $a['assessment_quality']['counts'])),
            $this->table('difficulty', 'Difficulty', ['level' => 'Level', 'count' => 'Questions', 'percentage' => 'Actual %', 'target_percentage' => 'Target %', 'difference' => 'Difference'], $a['difficulty']['distribution']),
            $this->table('cognitive', 'Bloom', ['level' => 'Level', 'count' => 'Questions', 'percentage' => 'Share %'], $a['cognitive']['distribution']),
            $this->table('co_coverage', 'CO Coverage', ['course_code' => 'Course', 'code' => 'Outcome', 'questions' => 'Questions', 'strong' => 'Strong', 'weak' => 'Weak', 'not_aligned' => 'Not Aligned', 'coverage_percentage' => 'Coverage %', 'status' => 'Status'], $a['learning_outcomes']['outcomes']),
            ($a['program_outcomes']['configured'] ?? false)
                ? $this->table('po_coverage', 'PO Coverage', ['code' => 'PO', 'title' => 'Title', 'mapped_cos' => 'Mapped COs', 'mapped_questions' => 'Questions', 'evidence_percent' => 'Evidence %', 'status' => 'Status'], $a['program_outcomes']['program_outcomes'])
                : $this->table('po_coverage', 'PO Coverage', ['message' => 'Status'], [['message' => $a['program_outcomes']['message'] ?? 'PO Mapping is not configured.']]),
            $this->table('performance', 'Student Performance', ['metric' => 'Metric', 'value' => 'Value'], $perfRows),
            $this->table('learning_gaps', 'Learning Gaps', ['code' => 'Outcome', 'assessment_title' => 'Assessment', 'average_percentage' => 'Average %', 'benchmark_percent' => 'Benchmark %', 'gap' => 'Gap', 'responses' => 'Responses', 'status' => 'Status'], $a['learning_gaps']['top_gaps']),
            $this->table('question_performance', 'Question Performance', ['assessment_title' => 'Assessment', 'question_number' => 'Question', 'co' => 'CO', 'difficulty' => 'Difficulty', 'cognitive_level' => 'Cognitive Level', 'average_percentage' => 'Average %', 'responses' => 'Responses', 'gap' => 'Gap', 'status' => 'Status'], $a['question_performance']),
            $this->table('topic_performance', 'Topic Performance', ['topic' => 'Topic', 'questions' => 'Questions', 'responses' => 'Responses', 'average_percentage' => 'Average %', 'gap' => 'Gap', 'status' => 'Status'], $a['topic_performance']),
            $this->table('similarity', 'Similarity', ['status' => 'Status', 'questions' => 'Questions', 'matches' => 'Matches'], array_map(fn ($s, $c) => ['status' => $s, 'questions' => $c['questions'], 'matches' => $c['matches']], array_keys($a['similarity']['by_status']), $a['similarity']['by_status'])),
            $this->table('rubrics', 'Rubrics', ['metric' => 'Metric', 'value' => 'Value'], [['metric' => 'Total', 'value' => $rubrics['total']], ['metric' => 'Approved', 'value' => $rubrics['approved']], ['metric' => 'Draft', 'value' => $rubrics['draft']], ['metric' => 'Average criteria', 'value' => $rubrics['average_criteria']], ['metric' => 'Faculty acceptance rate %', 'value' => $rubrics['faculty_ratings']['acceptance_rate']]]),
            $this->table('grading', 'Grading', ['metric' => 'Metric', 'value' => 'Value'], [['metric' => 'AI-assisted answers', 'value' => $grading['ai_assisted_answers']], ['metric' => 'Accepted', 'value' => $grading['faculty_accepted'] ?? null], ['metric' => 'Modified', 'value' => $grading['faculty_modified'] ?? null], ['metric' => 'Rejected', 'value' => $grading['faculty_rejected'] ?? null], ['metric' => 'MAE (AI vs final)', 'value' => $grading['mae'] ?? 'Not evaluated'], ['metric' => 'Exact agreement %', 'value' => $grading['exact_agreement_rate'] ?? 'Not evaluated']]),
            $this->table('inter_grader', 'Inter-Grader', ['metric' => 'Metric', 'value' => 'Value'], [['metric' => $a['inter_grader']['indicator_name'], 'value' => $a['inter_grader']['available'] ? 'Available' : 'Not available']]),
            $this->table('ai_evaluation', 'AI Evaluation', ['task' => 'Task', 'headline_metric' => 'Headline Metric', 'value' => 'Value', 'gate_status' => 'Gate', 'completed_at' => 'Evaluated At'], $evalRows),
            $this->table('recommendations', 'Recommendations', ['metric' => 'Metric', 'value' => 'Value'], array_map(fn ($k) => ['metric' => ucfirst(str_replace('_', ' ', $k)), 'value' => $a['recommendations'][$k]], ['total', 'active', 'accepted', 'dismissed', 'under_review'])),
            $this->table('collaboration', 'Collaboration', ['metric' => 'Metric', 'value' => 'Value'], array_map(fn ($k, $v) => ['metric' => ucfirst(str_replace('_', ' ', $k)), 'value' => is_scalar($v) ? $v : json_encode($v)], array_keys(array_filter($collab, 'is_scalar')), array_filter($collab, 'is_scalar'))),
            $this->table('attention', 'Attention Areas', ['severity' => 'Severity', 'type' => 'Type', 'title' => 'Signal', 'detail' => 'Detail', 'status' => 'Status'], $a['attention_areas']),
        ];
        $warnings = [];
        if ($ctx->assessmentIds === []) {
            $warnings[] = 'No assessments match the selected scope and filters.';
        }
        if ($a['scope']['student_data_restricted']) {
            $warnings[] = 'Student-performance sections are restricted for your role and are shown as unavailable.';
        }

        $doc = $this->document($ctx, $summary, [$this->section('disclaimer', 'Disclaimer', [], $a['meta']['disclaimer'])], $tables, $warnings, $a['meta']['generated_at']);
        $doc['has_data'] = $ctx->assessmentIds !== [];

        return $doc;
    }
}
