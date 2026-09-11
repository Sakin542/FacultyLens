<?php

namespace App\Services\Analytics;

use App\Models\AiEvaluationRun;
use App\Models\AiGradingResult;
use App\Models\Recommendation;
use App\Models\RecommendationFeedback;
use App\Models\Rubric;
use App\Models\User;
use App\Services\AiEvaluation\EvaluationReportService;
use App\Services\AiEvaluationService;
use Illuminate\Support\Facades\DB;

/**
 * STEP 36: AI-related analytics — rubrics (STEP 25), AI grading assistance (STEP 27), inter-grader (STEP 29, not deployed),
 * AI evaluation (STEP 35), recommendations and faculty feedback (STEP 14/20).
 * AI suggestions are never presented as official grades; feedback is an interaction signal, not accuracy.
 */
class AiAnalyticsService
{
    public function __construct(protected AnalyticsScopeService $scope, protected AiEvaluationService $evaluation) {}

    public function rubrics(array $assessmentIds): array
    {
        $base = Rubric::whereIn('assessment_id', $assessmentIds ?: [-1]);
        $byStatus = (clone $base)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->all();
        $total = array_sum($byStatus);
        $criteria = $total ? DB::table('rubric_criteria')->whereIn('rubric_id', (clone $base)->select('id'))->selectRaw('COUNT(*) AS c, COUNT(DISTINCT rubric_id) AS r')->first() : null;
        $byMethod = (clone $base)->selectRaw('generation_method, COUNT(*) AS c')->groupBy('generation_method')->pluck('c', 'generation_method')->all();
        $ratings = $total ? DB::table('ai_evaluation_ratings')->where('rateable_type', 'rubric')->whereIn('rateable_id', (clone $base)->select('id'))
            ->selectRaw('COUNT(*) AS n, AVG(overall_score) AS avg_score, SUM(decision = \'ACCEPTED\') AS accepted, SUM(decision = \'REVISED\') AS revised, SUM(decision = \'REJECTED\') AS rejected')->first() : null;
        $rated = (int) ($ratings->n ?? 0);
        $decided = (int) (($ratings->accepted ?? 0) + ($ratings->revised ?? 0) + ($ratings->rejected ?? 0));

        return [
            'total' => $total, 'draft' => (int) ($byStatus[Rubric::STATUS_DRAFT] ?? 0), 'approved' => (int) ($byStatus[Rubric::STATUS_APPROVED] ?? 0), 'archived' => (int) ($byStatus[Rubric::STATUS_ARCHIVED] ?? 0),
            'average_criteria' => $criteria && $criteria->r ? round($criteria->c / $criteria->r, 2) : null, 'by_generation_method' => $byMethod,
            'faculty_ratings' => ['rated' => $rated, 'average_rating' => $rated ? round((float) $ratings->avg_score, 2) : null,
                'acceptance_rate' => $decided ? round(($ratings->accepted / $decided) * 100, 1) : null, 'revision_rate' => $decided ? round(($ratings->revised / $decided) * 100, 1) : null,
                'note' => 'Faculty acceptance is an interaction signal, not objective AI accuracy.'],
        ];
    }

    /** AI suggestion vs final faculty grade for current, completed grading results with a faculty decision. */
    public function grading(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return ['available' => false, 'ai_assisted_answers' => 0];
        }
        $base = AiGradingResult::query()->join('student_submissions', 'student_submissions.id', '=', 'ai_grading_results.student_submission_id')
            ->whereIn('student_submissions.assessment_id', $assessmentIds)->where('ai_grading_results.is_current', true);
        $assisted = (clone $base)->whereIn('ai_grading_results.grading_status', [AiGradingResult::STATUS_COMPLETED, 'REVIEWED', 'FINALIZED'])->count();
        $decisions = (clone $base)->whereNotNull('ai_grading_results.faculty_decision')->selectRaw('ai_grading_results.faculty_decision AS d, COUNT(*) AS c')->groupBy('d')->pluck('c', 'd')->all();
        $cmp = (clone $base)->join('student_answers', 'student_answers.id', '=', 'ai_grading_results.student_answer_id')
            ->whereIn('student_submissions.grading_status', (array) config('performance.finalized_grading_statuses'))
            ->whereNotNull('student_answers.awarded_marks')->whereNotNull('ai_grading_results.suggested_marks')
            ->selectRaw('COUNT(*) AS n, AVG(ABS(ai_grading_results.suggested_marks - student_answers.awarded_marks)) AS mae, AVG(ai_grading_results.suggested_marks - student_answers.awarded_marks) AS signed,
                AVG(ai_grading_results.suggested_marks) AS ai_mean, AVG(student_answers.awarded_marks) AS faculty_mean, SUM(ai_grading_results.suggested_marks = student_answers.awarded_marks) AS exact_matches')->first();
        $n = (int) ($cmp->n ?? 0);

        return [
            'available' => $assisted > 0, 'ai_assisted_answers' => $assisted,
            'faculty_accepted' => (int) ($decisions[AiGradingResult::DECISION_ACCEPTED] ?? 0), 'faculty_modified' => (int) ($decisions[AiGradingResult::DECISION_MODIFIED] ?? 0), 'faculty_rejected' => (int) ($decisions[AiGradingResult::DECISION_REJECTED] ?? 0),
            'compared_answers' => $n, 'mae' => $n ? round((float) $cmp->mae, 2) : null, 'mean_signed_difference' => $n ? round((float) $cmp->signed, 2) : null,
            'ai_suggestion_mean' => $n ? round((float) $cmp->ai_mean, 2) : null, 'final_faculty_grade_mean' => $n ? round((float) $cmp->faculty_mean, 2) : null,
            'exact_agreement_rate' => $n ? round($cmp->exact_matches / $n * 100, 1) : null,
            'explanation' => config('analytics.explanations.grading'),
        ];
    }

    /** STEP 29 inter-grader consistency is not part of this deployment; report that honestly instead of fabricating. */
    public function interGrader(): array
    {
        return ['available' => false, 'message' => config('analytics.explanations.inter_grader'), 'indicator_name' => 'FacultyLens Agreement Indicator'];
    }

    /** STEP 35 headline metrics for evaluated tasks only + trend of completed runs. */
    public function evaluation(User $user): array
    {
        $overview = $this->evaluation->overview($user);
        $headline = EvaluationReportService::headlineMetrics();
        $tasks = array_map(fn ($t) => ['task' => $t['task'], 'evaluated' => $t['evaluated'], 'headline_metric' => $headline[$t['task']] ?? null, 'headline_value' => $t['headline_value'],
            'gate_status' => $t['gate_status'], 'run_id' => $t['run']['id'] ?? null, 'completed_at' => $t['run']['completed_at'] ?? null, 'example_count' => $t['run']['example_count'] ?? null, 'regression' => $t['regression']], $overview['tasks']);
        $trend = AiEvaluationRun::query()->where('status', AiEvaluationRun::STATUS_COMPLETED)
            ->when(!(method_exists($user, 'isAdmin') && $user->isAdmin()), fn ($q) => $q->where('created_by', $user->id))
            ->orderBy('id')->limit((int) config('analytics.trend_points_limit', 200))->get(['id', 'task', 'model_id', 'prompt_version_id', 'dataset_id', 'summary', 'completed_at', 'gate_status'])
            ->map(fn ($r) => ['run_id' => $r->id, 'task' => $r->task, 'model_id' => $r->model_id, 'prompt_version_id' => $r->prompt_version_id, 'dataset_id' => $r->dataset_id,
                'metric' => $r->summary['headline_metric'] ?? ($headline[$r->task] ?? null), 'value' => $r->summary['headline_value'] ?? null, 'gate_status' => $r->gate_status, 'completed_at' => $r->completed_at?->toISOString()])->values()->all();

        return ['overall_status' => $overview['overall_status'], 'evaluated_tasks' => count(array_filter($tasks, fn ($t) => $t['evaluated'])), 'tasks' => $tasks, 'trend' => $trend,
            'run_count' => $overview['run_count'], 'explanation' => config('analytics.explanations.ai_evaluation')];
    }

    /** Recommendation status counts (never counting comments) and STEP 20 feedback signals. */
    public function recommendations(array $assessmentIds): array
    {
        $reportIds = $this->scope->currentReportsQuery($assessmentIds)->pluck('id');
        $byStatus = Recommendation::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->all();
        $byPriority = Recommendation::whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds)->where('status', 'pending')->selectRaw('priority, COUNT(*) AS c')->groupBy('priority')->pluck('c', 'priority')->all();
        $fb = RecommendationFeedback::whereIn('recommendation_id', fn ($q) => $q->select('id')->from('recommendations')->whereIn('analysis_report_id', $reportIds->isEmpty() ? [-1] : $reportIds))
            ->selectRaw('decision, COUNT(*) AS c, AVG(usefulness_rating) AS r')->groupBy('decision')->get()->keyBy('decision');
        $fbTotal = (int) $fb->sum('c');
        $pct = fn (string $d) => $fbTotal ? round(((int) ($fb[$d]->c ?? 0)) / $fbTotal * 100, 1) : null;
        $total = array_sum($byStatus);

        return [
            'total' => $total, 'active' => (int) ($byStatus['pending'] ?? 0), 'accepted' => (int) ($byStatus['accepted'] ?? 0), 'dismissed' => (int) ($byStatus['dismissed'] ?? 0), 'under_review' => (int) ($byStatus['reviewed'] ?? 0),
            'active_by_priority' => ['high' => (int) ($byPriority['high'] ?? 0), 'medium' => (int) ($byPriority['medium'] ?? 0), 'low' => (int) ($byPriority['low'] ?? 0)],
            'feedback' => ['total' => $fbTotal, 'accepted_percent' => $pct('ACCEPTED'), 'dismissed_percent' => $pct('DISMISSED'), 'needs_review_percent' => $pct('REVIEWED'),
                'average_usefulness' => $fbTotal ? round((float) RecommendationFeedback::whereIn('recommendation_id', fn ($q) => $q->select('id')->from('recommendations')->whereIn('analysis_report_id', $reportIds))->avg('usefulness_rating'), 2) : null,
                'label' => 'Faculty Interaction Signal', 'explanation' => config('analytics.explanations.feedback')],
        ];
    }
}
