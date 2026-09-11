<?php

namespace App\Services\Analytics;

use App\Models\CoPoMappingAnalysisRun;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\QuestionLearningOutcomeAlignment;
use Illuminate\Support\Facades\DB;

/**
 * STEP 36: learning-outcome (STEP 11) and program-outcome (STEP 31) coverage analytics.
 * Coverage rule reused from performance.lo_alignment_levels: an outcome is covered by STRONG alignments.
 */
class OutcomeAnalyticsService
{
    public function __construct(protected AnalyticsScopeService $scope) {}

    public function learningOutcomes(array $courseIds, array $assessmentIds): array
    {
        $los = LearningOutcome::whereIn('course_id', $courseIds ?: [-1])->with('course:id,course_code')->orderBy('course_id')->orderBy('sort_order')->orderBy('code')->get();
        $reportIds = $this->scope->currentReportsQuery($assessmentIds)->pluck('id');
        $agg = QuestionLearningOutcomeAlignment::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)
            ->selectRaw("learning_outcome_id,
                COUNT(DISTINCT question_id) AS questions,
                COUNT(DISTINCT CASE WHEN alignment = 'STRONG_ALIGNMENT' THEN question_id END) AS strong,
                COUNT(DISTINCT CASE WHEN alignment = 'WEAK_ALIGNMENT' THEN question_id END) AS weak,
                COUNT(DISTINCT CASE WHEN alignment = 'NOT_ALIGNED' THEN question_id END) AS not_aligned")
            ->groupBy('learning_outcome_id')->get()->keyBy('learning_outcome_id');
        $strongLevels = (array) config('performance.lo_alignment_levels', ['STRONG_ALIGNMENT']);
        $weakPct = (float) config('analytics.weak_coverage_percent', 50);

        $rows = [];
        $covered = 0;
        foreach ($los as $lo) {
            $a = $agg[$lo->id] ?? null;
            $q = (int) ($a->questions ?? 0);
            $strong = (int) ($a->strong ?? 0);
            $weak = (int) ($a->weak ?? 0);
            $na = (int) ($a->not_aligned ?? 0);
            // Coverage % = share of aligned questions that meet the configured alignment level (STRONG), i.e. strong / (strong + weak + not_aligned)
            $coverage = $q ? round($strong / $q * 100, 1) : null;
            $isCovered = in_array('STRONG_ALIGNMENT', $strongLevels, true) ? $strong > 0 : $q > 0;
            $covered += $isCovered ? 1 : 0;
            $status = $q === 0 ? 'NOT_ASSESSED' : ($coverage >= $weakPct ? 'COVERED' : ($strong > 0 ? 'WEAK' : 'NOT_ALIGNED'));
            $rows[] = ['learning_outcome_id' => $lo->id, 'code' => $lo->code, 'description' => $lo->description, 'course_id' => $lo->course_id, 'course_code' => $lo->course?->course_code,
                'questions' => $q, 'strong' => $strong, 'weak' => $weak, 'not_aligned' => $na, 'coverage_percentage' => $coverage, 'status' => $status];
        }
        $n = $los->count();

        return ['total_outcomes' => $n, 'covered_outcomes' => $covered, 'coverage_percentage' => $n ? round($covered / $n * 100, 1) : null,
            'analyzed_assessments' => $reportIds->count(), 'outcomes' => $rows,
            'weak_outcomes' => array_values(array_filter($rows, fn ($r) => in_array($r['status'], ['WEAK', 'NOT_ALIGNED'], true))),
            'explanation' => config('analytics.explanations.co_coverage')];
    }

    /** Uses the current STEP 31 CO/PO run of each course; never fabricates PO data. */
    public function programOutcomes(array $courseIds): array
    {
        $courses = Course::whereIn('id', $courseIds ?: [-1])->get(['id', 'course_code', 'program_id']);
        $withProgram = $courses->whereNotNull('program_id');
        if ($withProgram->isEmpty()) {
            return ['configured' => false, 'message' => 'PO analysis is not configured for the selected course(s).', 'courses' => [], 'program_outcomes' => []];
        }
        $runs = CoPoMappingAnalysisRun::whereIn('course_id', $withProgram->pluck('id'))->where('is_current', true)
            ->whereIn('status', [CoPoMappingAnalysisRun::STATUS_COMPLETED, CoPoMappingAnalysisRun::STATUS_STALE])->get()->keyBy('course_id');
        $perCourse = [];
        $pos = [];
        foreach ($withProgram as $c) {
            $run = $runs[$c->id] ?? null;
            $summary = $run?->summary ?? [];
            $perCourse[] = ['course_id' => $c->id, 'course_code' => $c->course_code, 'analyzed' => (bool) $run, 'is_stale' => $run?->status === CoPoMappingAnalysisRun::STATUS_STALE,
                'analyzed_at' => $run?->analyzed_at?->toISOString(), 'co_coverage_percent' => $summary['co_coverage_percent'] ?? null,
                'po_evidence_percent' => $summary['po_evidence_percent'] ?? null, 'question_mapping_percent' => $summary['question_mapping_percent'] ?? null,
                'mapping_density_percent' => $summary['mapping_density_percent'] ?? null, 'finding_counts' => $summary['finding_counts'] ?? null];
            foreach ((array) ($run?->po_evidence ?? []) as $po) {
                $key = $po['program_outcome_id'] ?? $po['code'];
                $pos[$key] ??= ['program_outcome_id' => $po['program_outcome_id'] ?? null, 'code' => $po['code'] ?? '', 'title' => $po['title'] ?? '', 'courses' => 0,
                    'mapped_cos' => 0, 'mapped_questions' => 0, 'strong_mappings' => 0, 'weak_mappings' => 0, 'evidence_percent' => [], 'student_performance' => [], 'statuses' => []];
                $pos[$key]['courses']++;
                $pos[$key]['mapped_cos'] += (int) ($po['mapped_co_count'] ?? 0);
                $pos[$key]['mapped_questions'] += count($po['question_ids'] ?? []);
                foreach ($po['mapped_cos'] ?? [] as $m) {
                    if ((int) ($m['level'] ?? 0) >= 3) {
                        $pos[$key]['strong_mappings']++;
                    } elseif ((int) ($m['level'] ?? 0) > 0) {
                        $pos[$key]['weak_mappings']++;
                    }
                }
                $pos[$key]['evidence_percent'][] = (float) ($po['assessment_evidence_percent'] ?? 0);
                if (($po['student_performance_percent'] ?? null) !== null) {
                    $pos[$key]['student_performance'][] = (float) $po['student_performance_percent'];
                }
                $pos[$key]['statuses'][] = $po['evidence_status'] ?? 'NOT_MAPPED';
            }
        }
        $avg = fn (array $v) => $v ? round(array_sum($v) / count($v), 1) : null;
        $poRows = array_values(array_map(fn ($p) => ['program_outcome_id' => $p['program_outcome_id'], 'code' => $p['code'], 'title' => $p['title'], 'courses' => $p['courses'],
            'mapped_cos' => $p['mapped_cos'], 'mapped_questions' => $p['mapped_questions'], 'strong_mappings' => $p['strong_mappings'], 'weak_mappings' => $p['weak_mappings'],
            'evidence_percent' => $avg($p['evidence_percent']), 'student_performance_percent' => $avg($p['student_performance']),
            'status' => in_array('ASSESSED', $p['statuses'], true) ? 'ASSESSED' : (in_array('LIMITED_EVIDENCE', $p['statuses'], true) ? 'LIMITED_EVIDENCE' : 'NOT_MAPPED')], $pos));
        usort($poRows, fn ($a, $b) => strnatcmp($a['code'], $b['code']));
        $analyzed = count(array_filter($perCourse, fn ($c) => $c['analyzed']));

        return ['configured' => true, 'analyzed_courses' => $analyzed, 'unanalyzed_courses' => count($perCourse) - $analyzed,
            'message' => $analyzed === 0 ? 'A program is configured but no CO/PO analysis has been run yet.' : null,
            'courses' => $perCourse, 'program_outcomes' => $poRows,
            'unmapped_questions' => (int) DB::table('questions')->join('assessments', 'assessments.id', '=', 'questions.assessment_id')
                ->whereIn('assessments.course_id', $withProgram->pluck('id'))->whereNull('questions.learning_outcome_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('question_co_mappings')->whereColumn('question_co_mappings.question_id', 'questions.id')->where('question_co_mappings.status', 'CONFIRMED'))->count()];
    }
}
