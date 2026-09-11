<?php

namespace App\Services\Analytics;

use App\Models\Assessment;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\QuestionPerformanceResult;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\TopicPerformanceResult;
use App\Services\StudentPerformanceService;

/**
 * STEP 36: student performance analytics. Only finalized faculty grades are used (STEP 30 rules) and
 * gap classification is delegated to StudentPerformanceService so no second formula exists.
 * Callers must pass assessment ids from courses where the user may view student data.
 */
class PerformanceAnalyticsService
{
    public function __construct(protected AnalyticsScopeService $scope, protected StudentPerformanceService $performance) {}

    /** Aggregate statistics from finalized grades (no student identity). */
    public function summary(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return $this->emptySummary();
        }
        $perSubmission = StudentAnswer::query()
            ->join('student_submissions', 'student_submissions.id', '=', 'student_answers.student_submission_id')
            ->join('questions', 'questions.id', '=', 'student_answers.question_id')
            ->whereIn('student_submissions.assessment_id', $assessmentIds)
            ->whereIn('student_submissions.grading_status', (array) config('performance.finalized_grading_statuses'))
            ->where('student_answers.answer_status', StudentAnswer::STATUS_REVIEWED)
            ->whereNotNull('student_answers.awarded_marks')
            ->groupBy('student_submissions.id')
            ->selectRaw('student_submissions.id AS sid, SUM(student_answers.awarded_marks) AS awarded, SUM(questions.marks) AS max_marks, COUNT(*) AS answers')
            ->get();
        if ($perSubmission->isEmpty()) {
            return $this->emptySummary();
        }
        $pcts = $perSubmission->filter(fn ($r) => (float) $r->max_marks > 0)->map(fn ($r) => round((float) $r->awarded / (float) $r->max_marks * 100, 2))->sort()->values();
        $awarded = (float) $perSubmission->sum('awarded');
        $max = (float) $perSubmission->sum('max_marks');
        $avg = $max > 0 ? round($awarded / $max * 100, 2) : null;
        $responses = (int) $perSubmission->sum('answers');
        $n = $pcts->count();
        $median = $n ? ($n % 2 ? $pcts[intdiv($n, 2)] : round(($pcts[$n / 2 - 1] + $pcts[$n / 2]) / 2, 2)) : null;

        return [
            'available' => true,
            'average_percentage' => $avg,
            'median_percentage' => $median,
            'minimum_percentage' => $n ? $pcts->first() : null,
            'maximum_percentage' => $n ? $pcts->last() : null,
            'submissions' => $perSubmission->count(),
            'responses' => $responses,
            'benchmark_percent' => $this->performance->expected(),
            'gap' => $avg !== null ? round($this->performance->gap($avg), 2) : null,
            'status' => $this->performance->classify($avg, $responses),
            'explanation' => config('analytics.explanations.student_performance'),
        ];
    }

    protected function emptySummary(): array
    {
        return ['available' => false, 'average_percentage' => null, 'median_percentage' => null, 'minimum_percentage' => null, 'maximum_percentage' => null,
            'submissions' => 0, 'responses' => 0, 'benchmark_percent' => $this->performance->expected(), 'gap' => null, 'status' => PerformanceAnalysisRun::PERF_INSUFFICIENT,
            'explanation' => config('analytics.explanations.student_performance')];
    }

    /** Per-assessment trend from current STEP 30 runs (marks INSUFFICIENT_DATA rather than hiding). */
    public function trend(array $assessmentIds): array
    {
        $runs = $this->scope->currentPerformanceRunsQuery($assessmentIds)
            ->join('assessments', 'assessments.id', '=', 'performance_analysis_runs.assessment_id')
            ->orderByRaw('COALESCE(assessments.assessment_date, performance_analysis_runs.analyzed_at)')
            ->limit((int) config('analytics.trend_points_limit', 200))
            ->get(['performance_analysis_runs.*', 'assessments.title', 'assessments.type', 'assessments.assessment_date']);

        return $runs->map(fn ($r) => ['assessment_id' => (int) $r->assessment_id, 'title' => $r->title, 'type' => $r->type,
            'date' => $r->assessment_date ? substr((string) $r->assessment_date, 0, 10) : ($r->analyzed_at?->toDateString()),
            'average_percentage' => $r->overall_average_percentage !== null ? round((float) $r->overall_average_percentage, 2) : null,
            'responses' => (int) $r->finalized_answer_count, 'status' => $r->overall_status, 'sufficient' => $r->overall_status !== PerformanceAnalysisRun::PERF_INSUFFICIENT])->values()->all();
    }

    /** STEP 30 learning-outcome gap classification counts + top gaps. */
    public function gaps(array $assessmentIds): array
    {
        $runIds = $this->scope->currentPerformanceRunsQuery($assessmentIds)->pluck('id');
        $counts = array_fill_keys(PerformanceAnalysisRun::PERFORMANCE_STATUSES, 0);
        if ($runIds->isEmpty()) {
            return ['analyzed_assessments' => 0, 'counts' => $counts, 'open_gaps' => 0, 'top_gaps' => [], 'benchmark_percent' => $this->performance->expected(), 'explanation' => config('analytics.explanations.open_gaps')];
        }
        foreach (LearningOutcomePerformanceResult::whereIn('performance_analysis_run_id', $runIds)->selectRaw('performance_status, COUNT(*) AS c')->groupBy('performance_status')->pluck('c', 'performance_status') as $s => $c) {
            $counts[$s] = (int) $c;
        }
        $limit = (int) config('analytics.top_gaps_limit', 5);
        $top = LearningOutcomePerformanceResult::whereIn('performance_analysis_run_id', $runIds)
            ->whereIn('performance_status', PerformanceAnalysisRun::GAP_STATUSES)
            ->join('performance_analysis_runs', 'performance_analysis_runs.id', '=', 'learning_outcome_performance_results.performance_analysis_run_id')
            ->join('assessments', 'assessments.id', '=', 'performance_analysis_runs.assessment_id')
            ->orderByDesc('learning_outcome_performance_results.performance_gap')->limit($limit)
            ->get(['learning_outcome_performance_results.*', 'assessments.title AS assessment_title', 'performance_analysis_runs.assessment_id AS a_id'])
            ->map(fn ($r) => ['learning_outcome_id' => $r->learning_outcome_id, 'code' => $r->lo_code, 'description' => $r->lo_description, 'assessment_id' => (int) $r->a_id, 'assessment_title' => $r->assessment_title,
                'average_percentage' => round((float) $r->average_percentage, 2), 'benchmark_percent' => $this->performance->expected(), 'gap' => round((float) $r->performance_gap, 2),
                'responses' => (int) $r->response_count, 'status' => $r->performance_status])->values()->all();

        return ['analyzed_assessments' => $runIds->count(), 'counts' => $counts, 'open_gaps' => $counts['MINOR_GAP'] + $counts['MODERATE_GAP'] + $counts['HIGH_GAP'],
            'top_gaps' => $top, 'benchmark_percent' => $this->performance->expected(), 'explanation' => config('analytics.explanations.open_gaps')];
    }

    /** Question-level performance (current runs), sortable. */
    public function questions(array $assessmentIds, string $sort = 'worst'): array
    {
        $runIds = $this->scope->currentPerformanceRunsQuery($assessmentIds)->pluck('id');
        if ($runIds->isEmpty()) {
            return [];
        }
        $q = QuestionPerformanceResult::whereIn('performance_analysis_run_id', $runIds)
            ->join('performance_analysis_runs', 'performance_analysis_runs.id', '=', 'question_performance_results.performance_analysis_run_id')
            ->join('assessments', 'assessments.id', '=', 'performance_analysis_runs.assessment_id')
            ->leftJoin('questions', 'questions.id', '=', 'question_performance_results.question_id')
            ->leftJoin('learning_outcomes', 'learning_outcomes.id', '=', 'questions.learning_outcome_id')
            ->select(['question_performance_results.*', 'assessments.title AS assessment_title', 'performance_analysis_runs.assessment_id AS a_id', 'learning_outcomes.code AS lo_code']);
        match ($sort) {
            'gap' => $q->orderByDesc('question_performance_results.performance_gap'),
            'number' => $q->orderBy('assessments.id')->orderBy('question_performance_results.question_number'),
            'co' => $q->orderBy('learning_outcomes.code')->orderBy('question_performance_results.question_number'),
            default => $q->orderByRaw('question_performance_results.average_percentage IS NULL')->orderBy('question_performance_results.average_percentage'),
        };

        return $q->limit((int) config('analytics.question_table_limit', 200))->get()->map(fn ($r) => [
            'question_id' => $r->question_id, 'assessment_id' => (int) $r->a_id, 'assessment_title' => $r->assessment_title, 'question_number' => $r->question_number,
            'excerpt' => $r->question_text_excerpt, 'topics' => $r->topics ?? [], 'co' => $r->lo_code, 'difficulty' => $r->difficulty_level, 'cognitive_level' => $r->cognitive_level,
            'average_percentage' => $r->average_percentage !== null ? round((float) $r->average_percentage, 2) : null, 'responses' => (int) $r->response_count,
            'gap' => $r->performance_gap !== null ? round((float) $r->performance_gap, 2) : null, 'status' => $r->performance_status])->values()->all();
    }

    /** Topic-level performance aggregated across current runs (marks-weighted). */
    public function topics(array $assessmentIds): array
    {
        $runIds = $this->scope->currentPerformanceRunsQuery($assessmentIds)->pluck('id');
        if ($runIds->isEmpty()) {
            return [];
        }
        $rows = TopicPerformanceResult::whereIn('performance_analysis_run_id', $runIds)->get();
        $byTopic = [];
        foreach ($rows as $r) {
            $k = $r->topic;
            $byTopic[$k] ??= ['topic' => $k, 'questions' => 0, 'question_ids' => [], 'responses' => 0, 'weighted' => 0.0, 'weight' => 0.0, 'assessments' => 0];
            $byTopic[$k]['questions'] += (int) $r->question_count;
            $byTopic[$k]['question_ids'] = array_values(array_unique(array_merge($byTopic[$k]['question_ids'], (array) ($r->question_ids ?? []))));
            $byTopic[$k]['responses'] += (int) $r->response_count;
            $byTopic[$k]['assessments']++;
            if ($r->average_percentage !== null && (int) $r->response_count > 0) {
                $byTopic[$k]['weighted'] += (float) $r->average_percentage * (int) $r->response_count;
                $byTopic[$k]['weight'] += (int) $r->response_count;
            }
        }
        $out = array_map(function ($t) {
            $avg = $t['weight'] > 0 ? round($t['weighted'] / $t['weight'], 2) : null;

            return ['topic' => $t['topic'], 'questions' => $t['questions'], 'question_ids' => $t['question_ids'], 'responses' => $t['responses'], 'assessments' => $t['assessments'],
                'average_percentage' => $avg, 'gap' => $avg !== null ? round($this->performance->gap($avg), 2) : null, 'status' => $this->performance->classify($avg, $t['responses'])];
        }, $byTopic);
        usort($out, fn ($a, $b) => ($a['average_percentage'] ?? 999) <=> ($b['average_percentage'] ?? 999));

        return array_values($out);
    }

    /** Assessments in scope that have finalized grades but no current STEP 30 run (for empty-state hints). */
    public function assessmentsWithoutAnalysis(array $assessmentIds): int
    {
        if ($assessmentIds === []) {
            return 0;
        }
        $withRun = $this->scope->currentPerformanceRunsQuery($assessmentIds)->pluck('assessment_id')->all();
        $remaining = array_values(array_diff($assessmentIds, $withRun));

        return $remaining === [] ? 0 : (int) StudentSubmission::whereIn('assessment_id', $remaining)
            ->whereIn('grading_status', (array) config('performance.finalized_grading_statuses'))->distinct('assessment_id')->count('assessment_id');
    }
}
