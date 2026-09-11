<?php

namespace App\Services\Analytics;

use App\Models\Assessment;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionSimilarityMatch;
use App\Services\AssessmentReportService;
use Illuminate\Support\Facades\DB;

/**
 * STEP 36: assessment-level analytics (quality, difficulty, Bloom, similarity, question bank).
 * Reuses STEP 13 rating bands and difficulty targets; never re-runs analysis.
 */
class AssessmentAnalyticsService
{
    public const DIFFICULTY_LEVELS = ['easy', 'medium', 'hard'];
    public const COGNITIVE_LEVELS = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
    public const SIMILARITY_STATUSES = ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR', 'SOMEWHAT_SIMILAR', 'NOT_SIMILAR'];

    public function __construct(protected AnalyticsScopeService $scope, protected AssessmentReportService $reports) {}

    /** Quality counts by STEP 13 rating, average score and per-assessment time series. */
    public function quality(array $assessmentIds): array
    {
        $rows = $this->scope->currentReportsQuery($assessmentIds)
            ->join('assessments', 'assessments.id', '=', 'analysis_reports.assessment_id')
            ->orderByRaw('COALESCE(assessments.assessment_date, analysis_reports.analyzed_at, analysis_reports.created_at)')
            ->limit((int) config('analytics.trend_points_limit', 200))
            ->get(['analysis_reports.id', 'analysis_reports.assessment_id', 'analysis_reports.overall_score', 'analysis_reports.analyzed_at', 'analysis_reports.created_at',
                'assessments.title', 'assessments.type', 'assessments.assessment_date', 'assessments.course_id']);

        $counts = array_fill_keys((array) config('analytics.quality_ratings'), 0);
        $trend = [];
        foreach ($rows as $r) {
            $score = (float) $r->overall_score;
            $rating = $this->reports->getRatingLabel($score);
            $counts[$rating] = ($counts[$rating] ?? 0) + 1;
            $date = $r->assessment_date ?: ($r->analyzed_at ?: $r->created_at);
            $trend[] = ['assessment_id' => (int) $r->assessment_id, 'title' => $r->title, 'type' => $r->type, 'course_id' => (int) $r->course_id,
                'date' => $date ? substr((string) $date, 0, 10) : null, 'date_source' => $r->assessment_date ? 'assessment_date' : 'analyzed_at',
                'score' => round($score, 2), 'rating' => $rating];
        }
        $n = $rows->count();

        return [
            'analyzed_assessments' => $n,
            'average_score' => $n ? round($rows->avg('overall_score'), 2) : null,
            'counts' => $counts,
            'trend' => $trend,
            'explanation' => config('analytics.explanations.avg_quality'),
        ];
    }

    /** Faculty difficulty is authoritative; AI difficulty fills gaps. Count-based shares vs STEP 13 targets. */
    public function difficulty(array $assessmentIds): array
    {
        $rows = Question::whereIn('assessment_id', $assessmentIds ?: [-1])
            ->selectRaw('LOWER(COALESCE(difficulty_level, ai_difficulty_level)) AS level, COUNT(*) AS c')
            ->groupBy('level')->pluck('c', 'level')->all();
        $total = array_sum($rows);
        $targets = (array) config('analytics.difficulty_targets');
        $bands = (array) config('analytics.difficulty_balance');
        $dist = [];
        $deviation = 0.0;
        foreach (self::DIFFICULTY_LEVELS as $level) {
            $count = (int) ($rows[$level] ?? 0);
            $pct = $total ? round($count / $total * 100, 1) : null;
            $target = (float) ($targets[$level] ?? 0);
            $diff = $pct === null ? null : round($pct - $target, 1);
            $deviation += $pct === null ? 0 : abs($pct - $target);
            $dist[] = ['level' => $level, 'count' => $count, 'percentage' => $pct, 'target_percentage' => $target, 'difference' => $diff];
        }
        $unclassified = $total - array_sum(array_column($dist, 'count'));
        $status = null;
        if ($total > 0) {
            $status = $deviation > $bands['significant_deviation'] ? 'SIGNIFICANTLY_UNBALANCED' : ($deviation > $bands['slight_deviation'] ? 'SLIGHTLY_UNBALANCED' : 'BALANCED');
        }

        return ['total_questions' => $total, 'unclassified' => $unclassified, 'distribution' => $dist, 'total_deviation' => $total ? round($deviation, 1) : null,
            'balance_status' => $status, 'bands' => $bands, 'explanation' => config('analytics.explanations.difficulty')];
    }

    public function cognitive(array $assessmentIds): array
    {
        $rows = Question::whereIn('assessment_id', $assessmentIds ?: [-1])
            ->selectRaw('LOWER(COALESCE(cognitive_level, ai_cognitive_level)) AS level, COUNT(*) AS c')
            ->groupBy('level')->pluck('c', 'level')->all();
        $total = array_sum($rows);
        $dist = [];
        foreach (self::COGNITIVE_LEVELS as $level) {
            $count = (int) ($rows[strtolower($level)] ?? 0);
            $dist[] = ['level' => $level, 'count' => $count, 'percentage' => $total ? round($count / $total * 100, 1) : null];
        }
        $present = count(array_filter($dist, fn ($d) => $d['count'] > 0));

        return ['total_questions' => $total, 'unclassified' => $total - array_sum(array_column($dist, 'count')), 'distribution' => $dist,
            'distinct_levels' => $present, 'explanation' => config('analytics.explanations.cognitive')];
    }

    /** Per-assessment cognitive diversity used for attention signals. */
    public function lowCognitiveDiversity(array $assessmentIds): array
    {
        $minLevels = (int) config('analytics.min_cognitive_levels', 3);
        $minQuestions = (int) config('analytics.min_questions_for_signals', 5);
        $rows = Question::whereIn('assessment_id', $assessmentIds ?: [-1])
            ->selectRaw('assessment_id, COUNT(*) AS c, COUNT(DISTINCT LOWER(COALESCE(cognitive_level, ai_cognitive_level))) AS levels')
            ->groupBy('assessment_id')->havingRaw('COUNT(*) >= ?', [$minQuestions])->havingRaw('COUNT(DISTINCT LOWER(COALESCE(cognitive_level, ai_cognitive_level))) < ?', [$minLevels])
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $titles = Assessment::whereIn('id', $rows->pluck('assessment_id'))->pluck('title', 'id');

        return $rows->map(fn ($r) => ['assessment_id' => (int) $r->assessment_id, 'title' => $titles[$r->assessment_id] ?? "Assessment {$r->assessment_id}", 'questions' => (int) $r->c, 'distinct_levels' => (int) $r->levels])->values()->all();
    }

    /** STEP 12 similarity categories from the current analysis of each assessment (never called "duplicates"). */
    public function similarity(array $assessmentIds): array
    {
        $reportIds = $this->scope->currentReportsQuery($assessmentIds)->pluck('id');
        $counts = QuestionSimilarityMatch::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)
            ->selectRaw('similarity_status, COUNT(*) AS c, COUNT(DISTINCT current_question_id) AS q')->groupBy('similarity_status')->get()->keyBy('similarity_status');
        $out = [];
        foreach (self::SIMILARITY_STATUSES as $s) {
            $out[$s] = ['matches' => (int) ($counts[$s]->c ?? 0), 'questions' => (int) ($counts[$s]->q ?? 0)];
        }
        $flagged = QuestionSimilarityMatch::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)
            ->whereIn('similarity_status', ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR'])
            ->join('analysis_reports', 'analysis_reports.id', '=', 'question_similarity_matches.analysis_report_id')
            ->selectRaw('analysis_reports.assessment_id, COUNT(DISTINCT current_question_id) AS q')->groupBy('analysis_reports.assessment_id')->orderByDesc('q')->limit(10)->get()
            ->map(fn ($r) => ['assessment_id' => (int) $r->assessment_id, 'flagged_questions' => (int) $r->q])->values()->all();

        return ['analyzed_assessments' => $reportIds->count(), 'by_status' => $out, 'flagged_assessments' => $flagged,
            'thresholds' => ['POTENTIAL_DUPLICATE' => 0.85, 'HIGHLY_SIMILAR' => 0.70, 'SOMEWHAT_SIMILAR' => 0.50], 'explanation' => config('analytics.explanations.similarity')];
    }

    /** STEP 07 question bank (previous questions) + assessment questions in scope. */
    public function questionBank(array $courseIds, array $assessmentIds): array
    {
        $bank = PreviousQuestion::whereIn('course_id', $courseIds ?: [-1]);
        $total = (clone $bank)->count();
        $byDifficulty = (clone $bank)->selectRaw('LOWER(difficulty_level) AS k, COUNT(*) AS c')->groupBy('k')->pluck('c', 'k')->all();
        $byCognitive = (clone $bank)->selectRaw('LOWER(cognitive_level) AS k, COUNT(*) AS c')->groupBy('k')->pluck('c', 'k')->all();
        $bySource = (clone $bank)->selectRaw('source AS k, COUNT(*) AS c')->groupBy('k')->pluck('c', 'k')->all();
        $reportIds = $this->scope->currentReportsQuery($assessmentIds)->pluck('id');
        $matchedPrev = QuestionSimilarityMatch::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)
            ->whereIn('similarity_status', ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR'])->distinct('previous_question_id')->count('previous_question_id');
        $assessmentQuestions = Question::whereIn('assessment_id', $assessmentIds ?: [-1])->count();
        $byLo = Question::whereIn('assessment_id', $assessmentIds ?: [-1])->selectRaw('CASE WHEN learning_outcome_id IS NULL THEN 0 ELSE 1 END AS mapped, COUNT(*) AS c')->groupBy('mapped')->pluck('c', 'mapped')->all();

        return ['bank_questions' => $total, 'assessment_questions' => $assessmentQuestions, 'bank_previously_matched' => (int) $matchedPrev,
            'bank_by_difficulty' => $byDifficulty, 'bank_by_cognitive' => $byCognitive, 'bank_by_source' => $bySource,
            'assessment_questions_with_lo' => (int) ($byLo[1] ?? 0), 'assessment_questions_without_lo' => (int) ($byLo[0] ?? 0)];
    }

    /** Assessment list with headline metrics, used by compare and history views. */
    public function assessmentRows(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }
        $assessments = Assessment::whereIn('id', $assessmentIds)->with('course:id,course_code,course_name,semester,academic_year')->orderByRaw('COALESCE(assessment_date, created_at)')->get();
        $reports = $this->scope->currentReportsQuery($assessmentIds)->get()->keyBy('assessment_id');
        $questionStats = Question::whereIn('assessment_id', $assessmentIds)->selectRaw('assessment_id, COUNT(*) AS c')->groupBy('assessment_id')->pluck('c', 'assessment_id');
        $perf = $this->scope->currentPerformanceRunsQuery($assessmentIds)->get()->keyBy('assessment_id');
        // STEP 38: current version per assessment (latest FINALIZED, else latest non-archived) so dashboards never mix states
        $versions = \App\Models\AssessmentVersion::whereIn('assessment_id', $assessmentIds)->orderByDesc('version_number')->get(['id', 'assessment_id', 'version_number', 'version_label', 'status'])->groupBy('assessment_id');
        $rows = [];
        foreach ($assessments as $a) {
            $r = $reports[$a->id] ?? null;
            $p = $perf[$a->id] ?? null;
            $vs = $versions->get($a->id, collect());
            $cur = $vs->firstWhere('status', 'FINALIZED') ?? $vs->first(fn ($v) => $v->status !== 'ARCHIVED');
            $rows[] = [
                'assessment_id' => $a->id, 'title' => $a->title, 'type' => $a->type, 'status' => $a->status, 'date' => optional($a->assessment_date)->toDateString(),
                'course' => $a->course ? ['id' => $a->course->id, 'code' => $a->course->course_code, 'name' => $a->course->course_name, 'semester' => $a->course->semester, 'academic_year' => $a->course->academic_year] : null,
                'questions' => (int) ($questionStats[$a->id] ?? 0),
                'current_version' => $cur ? ['id' => $cur->id, 'version_number' => $cur->version_number, 'version_label' => $cur->version_label, 'status' => $cur->status] : null,
                'version_count' => $vs->count(), 'historical_version_ids' => $vs->filter(fn ($v) => !$cur || $v->id !== $cur->id)->pluck('id')->values()->all(),
                'quality_score' => $r ? round((float) $r->overall_score, 2) : null, 'quality_rating' => $r ? $this->reports->getRatingLabel((float) $r->overall_score) : null,
                'difficulty_balance_score' => $r?->difficulty_balance_score !== null ? round((float) $r->difficulty_balance_score, 2) : null,
                'cognitive_balance_score' => $r?->cognitive_level_balance_score !== null ? round((float) $r->cognitive_level_balance_score, 2) : null,
                'lo_alignment_score' => $r?->learning_outcome_alignment_score !== null ? round((float) $r->learning_outcome_alignment_score, 2) : null,
                'similar_questions' => $r ? (int) $r->similar_questions_count : null,
                'performance_percentage' => $p?->overall_average_percentage !== null ? round((float) $p->overall_average_percentage, 2) : null,
                'performance_status' => $p?->overall_status, 'performance_responses' => $p ? (int) $p->finalized_answer_count : null,
                'high_gaps' => $p ? (int) $p->learningOutcomeResults()->where('performance_status', 'HIGH_GAP')->count() : null,
            ];
        }

        return $rows;
    }

    /** Group scoped assessments by course term (semester/academic year) for historical course analytics. */
    public function history(array $assessmentIds): array
    {
        $rows = $this->assessmentRows($assessmentIds);
        $groups = [];
        foreach ($rows as $r) {
            $key = trim(($r['course']['academic_year'] ?? '') . ' ' . ($r['course']['semester'] ?? '')) ?: 'Unspecified term';
            $groups[$key] ??= ['term' => $key, 'academic_year' => $r['course']['academic_year'] ?? null, 'semester' => $r['course']['semester'] ?? null, 'assessments' => 0, 'quality' => [], 'performance' => [], 'lo_alignment' => []];
            $groups[$key]['assessments']++;
            if ($r['quality_score'] !== null) {
                $groups[$key]['quality'][] = $r['quality_score'];
            }
            if ($r['performance_percentage'] !== null) {
                $groups[$key]['performance'][] = $r['performance_percentage'];
            }
            if ($r['lo_alignment_score'] !== null) {
                $groups[$key]['lo_alignment'][] = $r['lo_alignment_score'];
            }
        }
        $avg = fn (array $v) => $v ? round(array_sum($v) / count($v), 2) : null;

        return array_values(array_map(fn ($g) => ['term' => $g['term'], 'academic_year' => $g['academic_year'], 'semester' => $g['semester'], 'assessments' => $g['assessments'],
            'average_quality' => $avg($g['quality']), 'analyzed_assessments' => count($g['quality']), 'average_performance' => $avg($g['performance']), 'performance_assessments' => count($g['performance']),
            'average_lo_alignment' => $avg($g['lo_alignment'])], $groups));
    }
}
