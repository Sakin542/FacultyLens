<?php

namespace App\Services\Reports;

use App\Models\AnalysisReport;
use App\Models\AssessmentVersion;
use App\Services\Analytics\AssessmentAnalyticsService;
use App\Services\AssessmentReportService;

/**
 * Assessment Quality Report — STEP 13 dimensions using the existing rating scale
 * (90 EXCELLENT / 80 GOOD / 70 FAIR / 60 NEEDS_REVIEW / <60 REQUIRES_ATTENTION). No second algorithm.
 */
class AssessmentQualityReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AssessmentReportService $reports, protected AssessmentAnalyticsService $analytics) {}

    public function build(ReportContext $ctx): array
    {
        $q = AnalysisReport::query()->where('analysis_status', 'completed')
            ->join('assessments', 'assessments.id', '=', 'analysis_reports.assessment_id')
            ->join('courses', 'courses.id', '=', 'assessments.course_id')
            ->whereIn('analysis_reports.assessment_id', $ctx->assessmentIds ?: [-1]);
        if ($ctx->version) {
            // Frozen to the analysis attached to this exact version (or the current analysis of identical content)
            $bound = $this->analysisFor($ctx);
            $q->where('analysis_reports.id', $bound?->id ?? -1);
        } else {
            $q->where('analysis_reports.is_current', true);
        }
        $reports = $q->orderBy('courses.course_code')->orderBy('assessments.title')
            ->get(['analysis_reports.*', 'assessments.title AS assessment_title', 'assessments.type AS assessment_type', 'assessments.assessment_date', 'courses.course_code', 'courses.course_name']);

        $versionLabels = AssessmentVersion::whereIn('id', $reports->pluck('assessment_version_id')->filter())->pluck('version_label', 'id');
        $rows = $reports->map(function ($r) use ($versionLabels, $ctx) {
            $score = (float) $r->overall_score;

            return ['course_code' => $r->course_code, 'assessment' => $r->assessment_title, 'assessment_type' => $r->assessment_type,
                'version' => $ctx->version?->version_label ?? ($r->assessment_version_id ? ($versionLabels[$r->assessment_version_id] ?? null) : null),
                'overall_quality' => round($score, 2), 'rating' => $this->reports->getRatingLabel($score),
                'topic_coverage' => $this->score($r->topic_coverage_score), 'lo_coverage' => $this->score($r->learning_outcome_alignment_score),
                'difficulty_balance' => $this->score($r->difficulty_balance_score), 'cognitive_diversity' => $this->score($r->cognitive_level_balance_score),
                'question_diversity' => $this->score($r->similarity_score), 'marks_distribution' => $this->marksDistribution($r),
                'questions' => (int) $r->total_questions, 'similar_questions' => (int) $r->similar_questions_count, 'analyzed_at' => $r->analyzed_at?->toDateTimeString()];
        })->values()->all();

        $counts = array_fill_keys((array) config('analytics.quality_ratings'), 0);
        foreach ($rows as $row) {
            $counts[$row['rating']] = ($counts[$row['rating']] ?? 0) + 1;
        }
        $avg = $rows ? round(array_sum(array_column($rows, 'overall_quality')) / count($rows), 2) : null;
        $warnings = [];
        $unanalyzed = count($ctx->assessmentIds) - count($rows);
        if ($unanalyzed > 0 && ! $ctx->version) {
            $warnings[] = "{$unanalyzed} assessment(s) in scope have no completed analysis and are excluded.";
        }
        if ($rows === []) {
            $warnings[] = 'No completed quality analysis exists for the selected scope.';
        }

        $summary = [
            $this->kv('Assessments in Scope', count($ctx->assessmentIds)),
            $this->kv('Analyzed Assessments', count($rows)),
            $this->kv('Average Overall Quality', $this->na($avg, '', 'Not analyzed')),
            $this->kv('Average Rating', $avg === null ? 'Not analyzed' : $this->reports->getRatingLabel($avg)),
        ];
        foreach ($counts as $rating => $c) {
            $summary[] = $this->kv($this->humanize($rating), $c);
        }

        $tables = [
            $this->table('quality', 'Assessment Quality (STEP 13)', [
                'course_code' => 'Course', 'assessment' => 'Assessment', 'assessment_type' => 'Type', 'version' => 'Version', 'overall_quality' => 'Overall Quality', 'rating' => 'Rating',
                'topic_coverage' => 'Topic Coverage', 'lo_coverage' => 'LO Coverage', 'difficulty_balance' => 'Difficulty Balance', 'cognitive_diversity' => 'Cognitive Diversity',
                'question_diversity' => 'Question Diversity', 'marks_distribution' => 'Marks Distribution', 'questions' => 'Questions', 'similar_questions' => 'Similar Questions', 'analyzed_at' => 'Analyzed At',
            ], $rows, 'Scores are 0–100. Ratings: 90–100 Excellent, 80–89 Good, 70–79 Fair, 60–69 Needs Review, below 60 Requires Attention.'),
            $this->table('rating_counts', 'Rating Distribution', ['rating' => 'Rating', 'assessments' => 'Assessments'], $rows === [] ? [] : array_map(fn ($r, $c) => ['rating' => $r, 'assessments' => $c], array_keys($counts), $counts)),
        ];
        if (count($rows) === 1) {
            $tables[] = $this->dimensionTable($rows[0]);
        }

        return $this->document($ctx, $summary, [], $tables, $warnings, $reports->max('analyzed_at')?->toISOString());
    }

    protected function dimensionTable(array $row): array
    {
        $dims = ['topic_coverage' => ['Topic Coverage', '25%'], 'lo_coverage' => ['LO Coverage', '25%'], 'difficulty_balance' => ['Difficulty Balance', '15%'], 'cognitive_diversity' => ['Cognitive Diversity', '15%'], 'question_diversity' => ['Question Diversity', '10%'], 'marks_distribution' => ['Marks Distribution', '10%']];
        $rows = [];
        foreach ($dims as $k => [$label, $weight]) {
            $rows[] = ['dimension' => $label, 'score' => $row[$k], 'rating' => $row[$k] === null ? 'Not evaluated' : $this->reports->getRatingLabel((float) $row[$k]), 'weight' => $weight];
        }

        return $this->table('dimensions', 'Quality Dimensions', ['dimension' => 'Dimension', 'score' => 'Score', 'rating' => 'Rating', 'weight' => 'Weight'], $rows);
    }

    protected function score(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    /** Marks-distribution score lives inside the findings payload (STEP 13); absent → null (never zero). */
    protected function marksDistribution(AnalysisReport $r): ?float
    {
        $f = is_string($r->findings) ? json_decode($r->findings, true) : $r->findings;
        $v = $f['quality_analysis']['marks_distribution_score'] ?? $f['quality_analysis']['dimensions']['marks_distribution']['score'] ?? null;

        return $v === null ? null : round((float) $v, 2);
    }
}
