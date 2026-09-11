<?php

namespace App\Services\Analytics;

use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * STEP 36: builds the unified analytics overview. Aggregates evidence only — it never modifies
 * assessments, grades, mappings, rubrics or models.
 */
class AcademicAnalyticsService
{
    public function __construct(
        protected AnalyticsScopeService $scope,
        protected AssessmentAnalyticsService $assessments,
        protected OutcomeAnalyticsService $outcomes,
        protected PerformanceAnalyticsService $performance,
        protected AiAnalyticsService $ai,
        protected CollaborationAnalyticsService $collaboration,
    ) {}

    public function overview(User $user, array $filters, bool $fresh = false): array
    {
        $filters = $this->scope->normalize($filters);
        $courseIds = $this->scope->courseIds($user, $filters);
        $assessmentIds = $this->scope->assessmentIds($courseIds, $filters);
        $version = $this->scope->dataVersion($courseIds, $assessmentIds);
        $ttl = (int) config('analytics.cache_ttl', 300);
        $key = 'analytics:overview:' . $user->id . ':' . sha1(json_encode($filters) . '|' . $version);
        if ($fresh) {
            Cache::forget($key);
        }
        $cached = Cache::has($key);
        $data = Cache::remember($key, $ttl, fn () => $this->build($user, $filters, $courseIds, $assessmentIds));
        $data['meta']['cached'] = $cached;
        $data['meta']['cache_ttl_seconds'] = $ttl;
        $data['meta']['served_at'] = now()->toISOString();

        return $data;
    }

    /**
     * STEP 39: the same overview for an already-authorized course/assessment scope (department or
     * institution reports). Uncached — reports snapshot their own output.
     */
    public function overviewForScope(User $user, array $filters, array $courseIds, array $assessmentIds): array
    {
        $data = $this->build($user, $this->scope->normalize($filters), $courseIds, $assessmentIds);
        $data['meta']['cached'] = false;
        $data['meta']['served_at'] = now()->toISOString();

        return $data;
    }

    protected function build(User $user, array $filters, array $courseIds, array $assessmentIds): array
    {
        $studentCourseIds = $this->scope->studentDataCourseIds($user, $courseIds);
        $studentAssessmentIds = $studentCourseIds === [] ? [] : array_values(array_intersect($assessmentIds, $this->scope->assessmentIds($studentCourseIds, $filters)));

        $quality = $this->assessments->quality($assessmentIds);
        $difficulty = $this->assessments->difficulty($assessmentIds);
        $cognitive = $this->assessments->cognitive($assessmentIds);
        $lo = $this->outcomes->learningOutcomes($courseIds, $assessmentIds);
        $po = $this->outcomes->programOutcomes($courseIds);
        $perf = $this->performance->summary($studentAssessmentIds);
        $perfTrend = $this->performance->trend($studentAssessmentIds);
        $gaps = $this->performance->gaps($studentAssessmentIds);
        $questions = $this->performance->questions($studentAssessmentIds, (string) ($filters['sort'] ?? 'worst'));
        $topics = $this->performance->topics($studentAssessmentIds);
        $similarity = $this->assessments->similarity($assessmentIds);
        $bank = $this->assessments->questionBank($courseIds, $assessmentIds);
        $rubrics = $this->ai->rubrics($assessmentIds);
        $grading = $this->ai->grading($studentAssessmentIds);
        $evaluation = $this->ai->evaluation($user);
        $recommendations = $this->ai->recommendations($assessmentIds);
        $collab = $this->collaboration->summary($courseIds);
        $activity = $this->collaboration->activity($courseIds);
        $lowDiversity = $this->assessments->lowCognitiveDiversity($assessmentIds);
        $rows = $this->assessments->assessmentRows($assessmentIds);
        $blueprints = $this->blueprintCompliance($assessmentIds);

        $kpis = [
            'courses' => ['value' => count($courseIds), 'label' => 'Courses'],
            'assessments' => ['value' => count($assessmentIds), 'label' => 'Assessments'],
            'questions' => ['value' => Question::whereIn('assessment_id', $assessmentIds ?: [-1])->count(), 'label' => 'Questions'],
            'average_quality' => ['value' => $quality['average_score'], 'label' => 'Avg Quality', 'unit' => 'score', 'explanation' => config('analytics.explanations.avg_quality'), 'basis' => $quality['analyzed_assessments'] . ' analyzed'],
            'student_performance' => ['value' => $perf['average_percentage'], 'label' => 'Student Performance', 'unit' => 'percent', 'explanation' => config('analytics.explanations.student_performance'),
                'basis' => $perf['available'] ? $perf['submissions'] . ' finalized submissions' : ($studentCourseIds === [] && $courseIds !== [] ? 'Restricted for your role' : 'No finalized grades')],
            'co_coverage' => ['value' => $lo['coverage_percentage'], 'label' => 'CO Coverage', 'unit' => 'percent', 'explanation' => config('analytics.explanations.co_coverage'), 'basis' => $lo['covered_outcomes'] . '/' . $lo['total_outcomes'] . ' outcomes'],
            'open_gaps' => ['value' => $gaps['analyzed_assessments'] ? $gaps['open_gaps'] : null, 'label' => 'Open Gaps', 'explanation' => config('analytics.explanations.open_gaps'), 'basis' => $gaps['analyzed_assessments'] . ' performance analyses'],
            'ai_analysis_runs' => ['value' => $quality['analyzed_assessments'], 'label' => 'AI Analysis Runs', 'basis' => 'current completed analyses'],
        ];

        return [
            'filters' => $filters,
            'scope' => ['course_ids' => $courseIds, 'assessment_ids' => $assessmentIds, 'student_data_course_ids' => $studentCourseIds, 'student_data_restricted' => $courseIds !== [] && $studentCourseIds === []],
            'kpis' => $kpis,
            'assessment_quality' => $quality,
            'difficulty' => $difficulty,
            'cognitive' => $cognitive,
            'learning_outcomes' => $lo,
            'program_outcomes' => $po,
            'performance' => $perf + ['trend' => $perfTrend, 'assessments_without_analysis' => $this->performance->assessmentsWithoutAnalysis($studentAssessmentIds)],
            'learning_gaps' => $gaps,
            'question_performance' => $questions,
            'topic_performance' => $topics,
            'similarity' => $similarity,
            'question_bank' => $bank,
            'rubrics' => $rubrics,
            'grading' => $grading,
            'inter_grader' => $this->ai->interGrader(),
            'ai_evaluation' => $evaluation,
            'recommendations' => $recommendations,
            'collaboration' => $collab + ['activity' => $activity],
            'assessments' => $rows,
            'blueprint_compliance' => $blueprints,
            'attention_areas' => $this->attentionAreas($gaps, $quality, $rows, $lo, $po, $similarity, $lowDiversity, $difficulty),
            'meta' => ['generated_at' => now()->toISOString(), 'benchmark_percent' => $perf['benchmark_percent'], 'disclaimer' => 'Analytics are evidence, trends and signals for faculty review. FacultyLens never changes assessments, grades, mappings, rubrics or AI models automatically.'],
        ];
    }

    /** STEP 37: compliance of each assessment's current blueprint with its actual question set (informational). */
    protected function blueprintCompliance(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return ['assessments_with_blueprint' => 0, 'average_compliance' => null, 'rows' => []];
        }
        $service = app(\App\Services\AssessmentBlueprintService::class);
        $rows = [];
        $blueprints = \App\Models\AssessmentBlueprint::whereIn('assessment_id', $assessmentIds)->where('is_current', true)->with('assessment:id,title')->get();
        foreach ($blueprints as $bp) {
            $c = $service->compliance($bp->assessment);
            if ($c) {
                $rows[] = $c + ['assessment_id' => $bp->assessment_id, 'assessment_title' => $bp->assessment->title];
            }
        }
        $values = array_filter(array_column($rows, 'compliance_percent'), fn ($v) => $v !== null);

        return ['assessments_with_blueprint' => count($rows), 'average_compliance' => $values ? round(array_sum($values) / count($values), 1) : null, 'rows' => $rows];
    }

    /**
     * Prioritised signals reusing STEP 13/14/30/31/12 evidence: HIGH GAP → quality issue → weak CO/PO coverage → similarity → other.
     */
    protected function attentionAreas(array $gaps, array $quality, array $rows, array $lo, array $po, array $similarity, array $lowDiversity, array $difficulty): array
    {
        $items = [];
        foreach ($gaps['top_gaps'] as $g) {
            $sev = $g['status'] === 'HIGH_GAP' ? 'HIGH' : ($g['status'] === 'MODERATE_GAP' ? 'MEDIUM' : 'LOW');
            $items[] = ['severity' => $sev, 'type' => 'LEARNING_GAP', 'title' => "{$g['code']} performance is below benchmark", 'detail' => "Average {$g['average_percentage']}% vs benchmark {$g['benchmark_percent']}% (gap {$g['gap']}%, {$g['responses']} responses) — {$g['assessment_title']}", 'status' => $g['status'],
                'link' => ['type' => 'assessment', 'id' => $g['assessment_id']]];
        }
        foreach ($rows as $r) {
            if ($r['quality_rating'] !== null && in_array($r['quality_rating'], (array) config('analytics.low_quality_ratings'), true)) {
                $items[] = ['severity' => $r['quality_rating'] === 'REQUIRES_ATTENTION' ? 'HIGH' : 'MEDIUM', 'type' => 'QUALITY', 'title' => "{$r['title']} quality is rated " . str_replace('_', ' ', strtolower($r['quality_rating'])),
                    'detail' => "Overall quality score {$r['quality_score']}", 'status' => $r['quality_rating'], 'link' => ['type' => 'assessment', 'id' => $r['assessment_id']]];
            }
        }
        foreach ($lo['weak_outcomes'] as $w) {
            $items[] = ['severity' => $w['status'] === 'NOT_ALIGNED' ? 'MEDIUM' : 'LOW', 'type' => 'CO_COVERAGE', 'title' => "{$w['code']} has weak learning-outcome coverage",
                'detail' => "{$w['strong']} strong / {$w['weak']} weak / {$w['not_aligned']} not aligned across {$w['questions']} aligned questions", 'status' => $w['status'], 'link' => ['type' => 'course', 'id' => $w['course_id']]];
        }
        if ($po['configured'] ?? false) {
            foreach ($po['program_outcomes'] as $p) {
                if ($p['status'] !== 'ASSESSED') {
                    $items[] = ['severity' => 'LOW', 'type' => 'PO_COVERAGE', 'title' => "{$p['code']} has " . ($p['status'] === 'NOT_MAPPED' ? 'no CO mapping' : 'limited assessment evidence'), 'detail' => $p['title'], 'status' => $p['status'], 'link' => null];
                }
            }
        }
        $dupes = $similarity['by_status']['POTENTIAL_DUPLICATE']['questions'] ?? 0;
        if ($dupes > 0) {
            $items[] = ['severity' => 'MEDIUM', 'type' => 'SIMILARITY', 'title' => "{$dupes} question(s) have potential-duplicate similarity", 'detail' => 'Similarity ≥ 0.85 with previous questions (STEP 12). Faculty review required.', 'status' => 'POTENTIAL_DUPLICATE',
                'link' => $similarity['flagged_assessments'] ? ['type' => 'assessment', 'id' => $similarity['flagged_assessments'][0]['assessment_id']] : null];
        }
        foreach ($lowDiversity as $d) {
            $items[] = ['severity' => 'MEDIUM', 'type' => 'COGNITIVE_DIVERSITY', 'title' => "{$d['title']} has low cognitive diversity", 'detail' => "Only {$d['distinct_levels']} Bloom level(s) across {$d['questions']} questions", 'status' => 'LOW_DIVERSITY', 'link' => ['type' => 'assessment', 'id' => $d['assessment_id']]];
        }
        if (($difficulty['balance_status'] ?? null) === 'SIGNIFICANTLY_UNBALANCED') {
            $items[] = ['severity' => 'MEDIUM', 'type' => 'DIFFICULTY', 'title' => 'Difficulty distribution deviates significantly from the target profile', 'detail' => "Total deviation {$difficulty['total_deviation']}% from Easy/Medium/Hard targets", 'status' => 'SIGNIFICANTLY_UNBALANCED', 'link' => null];
        }
        $order = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2];
        $typeOrder = ['LEARNING_GAP' => 0, 'QUALITY' => 1, 'CO_COVERAGE' => 2, 'PO_COVERAGE' => 3, 'SIMILARITY' => 4, 'COGNITIVE_DIVERSITY' => 5, 'DIFFICULTY' => 6];
        usort($items, fn ($a, $b) => [$order[$a['severity']], $typeOrder[$a['type']]] <=> [$order[$b['severity']], $typeOrder[$b['type']]]);

        return array_slice($items, 0, (int) config('analytics.attention_limit', 10));
    }

    /** Side-by-side assessment comparison (informational only). */
    public function compare(User $user, array $assessmentIds): array
    {
        $courseIds = $this->scope->courseIds($user, []);
        $allowed = $this->scope->assessmentIds($courseIds, []);
        $ids = array_values(array_intersect(array_map('intval', $assessmentIds), $allowed));
        $studentCourseIds = $this->scope->studentDataCourseIds($user, $courseIds);
        $studentAllowed = $this->scope->assessmentIds($studentCourseIds, []);
        $rows = [];
        foreach ($ids as $id) {
            $one = [$id];
            $row = $this->assessments->assessmentRows($one)[0] ?? null;
            if (!$row) {
                continue;
            }
            $canStudent = in_array($id, $studentAllowed, true);
            $row['difficulty'] = $this->assessments->difficulty($one);
            $row['cognitive'] = $this->assessments->cognitive($one);
            $row['learning_outcomes'] = $this->outcomes->learningOutcomes([$row['course']['id']], $one);
            $row['similarity'] = $this->assessments->similarity($one)['by_status'];
            $row['performance'] = $canStudent ? $this->performance->summary($one) : ['available' => false, 'restricted' => true];
            $row['learning_gaps'] = $canStudent ? $this->performance->gaps($one) : null;
            $rows[] = $row;
        }

        return ['assessments' => $rows, 'requested' => count($assessmentIds), 'authorized' => count($rows), 'note' => 'Comparison is informational; no assessment is changed.'];
    }

    public function courseHistory(User $user, Course $course, array $filters = []): array
    {
        $filters = $this->scope->normalize($filters);
        // Historical view spans every term of the same course code the user can access
        $courseIds = $this->scope->accessibleCourseIdsByCode($user, $course->course_code);
        $assessmentIds = $this->scope->assessmentIds($courseIds, $filters);

        return ['course_code' => $course->course_code, 'course_name' => $course->course_name, 'course_ids' => $courseIds, 'terms' => $this->assessments->history($assessmentIds), 'assessments' => $this->assessments->assessmentRows($assessmentIds)];
    }

    /** Flat CSV rows for export (aggregates only; no student identity). */
    public function toCsv(array $overview): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['section', 'metric', 'value', 'detail']);
        foreach ($overview['kpis'] as $k => $kpi) {
            fputcsv($out, ['kpi', $k, $kpi['value'] ?? 'N/A', $kpi['basis'] ?? '']);
        }
        foreach ($overview['assessment_quality']['counts'] as $rating => $c) {
            fputcsv($out, ['assessment_quality', $rating, $c, '']);
        }
        foreach ($overview['difficulty']['distribution'] as $d) {
            fputcsv($out, ['difficulty', $d['level'], $d['percentage'] ?? 'N/A', "count={$d['count']}; target={$d['target_percentage']}; difference=" . ($d['difference'] ?? 'N/A')]);
        }
        foreach ($overview['cognitive']['distribution'] as $d) {
            fputcsv($out, ['cognitive', $d['level'], $d['percentage'] ?? 'N/A', "count={$d['count']}"]);
        }
        foreach ($overview['learning_outcomes']['outcomes'] as $o) {
            fputcsv($out, ['learning_outcome', $o['code'], $o['coverage_percentage'] ?? 'N/A', "questions={$o['questions']}; strong={$o['strong']}; weak={$o['weak']}; not_aligned={$o['not_aligned']}; status={$o['status']}"]);
        }
        foreach ($overview['program_outcomes']['program_outcomes'] ?? [] as $p) {
            fputcsv($out, ['program_outcome', $p['code'], $p['evidence_percent'] ?? 'N/A', "mapped_cos={$p['mapped_cos']}; status={$p['status']}"]);
        }
        foreach (['average_percentage', 'median_percentage', 'minimum_percentage', 'maximum_percentage', 'submissions', 'responses', 'status'] as $m) {
            fputcsv($out, ['performance', $m, $overview['performance'][$m] ?? 'N/A', '']);
        }
        foreach ($overview['learning_gaps']['counts'] as $s => $c) {
            fputcsv($out, ['learning_gaps', $s, $c, '']);
        }
        foreach ($overview['similarity']['by_status'] as $s => $c) {
            fputcsv($out, ['similarity', $s, $c['questions'], "matches={$c['matches']}"]);
        }
        foreach ($overview['ai_evaluation']['tasks'] as $t) {
            fputcsv($out, ['ai_evaluation', $t['task'], $t['evaluated'] ? $t['headline_value'] : 'Not evaluated yet', $t['headline_metric'] ?? '']);
        }
        foreach (['total', 'active', 'accepted', 'dismissed', 'under_review'] as $m) {
            fputcsv($out, ['recommendations', $m, $overview['recommendations'][$m], '']);
        }
        foreach ($overview['attention_areas'] as $a) {
            fputcsv($out, ['attention', $a['type'], $a['severity'], $a['title'] . ' — ' . $a['detail']]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
