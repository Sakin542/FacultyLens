<?php

namespace App\Services\Reports;

use App\Models\AiGradingResult;
use App\Models\StudentAnswer;
use App\Services\Analytics\AiAnalyticsService;

/**
 * Grading Report — aggregated finalized faculty grades per question and the grading-method
 * distribution (FACULTY vs AI_ASSISTED). AI suggestions are never presented as final grades.
 */
class GradingReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AiAnalyticsService $ai) {}

    public function build(ReportContext $ctx): array
    {
        $ids = $ctx->studentDataAssessmentIds;
        $finalized = (array) config('performance.finalized_grading_statuses');
        $rows = $ids === [] ? collect() : StudentAnswer::query()
            ->join('student_submissions', 'student_submissions.id', '=', 'student_answers.student_submission_id')
            ->join('questions', 'questions.id', '=', 'student_answers.question_id')
            ->join('assessments', 'assessments.id', '=', 'student_submissions.assessment_id')
            ->whereIn('student_submissions.assessment_id', $ids)->whereIn('student_submissions.grading_status', $finalized)
            ->where('student_answers.answer_status', StudentAnswer::STATUS_REVIEWED)->whereNotNull('student_answers.awarded_marks')
            ->groupBy('questions.id', 'questions.question_number', 'questions.marks', 'assessments.title')
            ->selectRaw('questions.id AS qid, assessments.title AS assessment, questions.question_number AS question, questions.marks AS max_marks, COUNT(*) AS responses, AVG(student_answers.awarded_marks) AS avg_marks, MIN(student_answers.awarded_marks) AS min_marks, MAX(student_answers.awarded_marks) AS max_awarded,
                SUM(CASE WHEN EXISTS (SELECT 1 FROM ai_grading_results g WHERE g.student_answer_id = student_answers.id AND g.is_current = 1 AND g.faculty_decision IS NOT NULL) THEN 1 ELSE 0 END) AS ai_assisted')
            ->orderBy('assessments.title')->orderBy('questions.question_number')->get();

        $questionRows = $rows->map(fn ($r) => ['assessment' => $r->assessment, 'question' => (int) $r->question, 'max_marks' => (float) $r->max_marks, 'responses' => (int) $r->responses,
            'average_marks' => round((float) $r->avg_marks, 2), 'average_percentage' => (float) $r->max_marks > 0 ? round((float) $r->avg_marks / (float) $r->max_marks * 100, 2) : null,
            'minimum_marks' => (float) $r->min_marks, 'maximum_marks' => (float) $r->max_awarded,
            'ai_assisted_responses' => (int) $r->ai_assisted, 'faculty_only_responses' => (int) $r->responses - (int) $r->ai_assisted])->values()->all();

        $totalResponses = (int) $rows->sum('responses');
        $aiAssisted = (int) $rows->sum('ai_assisted');
        $grading = $this->ai->grading($ids);

        $summary = [
            $this->kv('Assessments in Scope', count($ids)),
            $this->kv('Questions with Finalized Grades', count($questionRows)),
            $this->kv('Finalized Responses', $totalResponses),
            $this->kv('Faculty-Only Graded', $totalResponses - $aiAssisted),
            $this->kv('AI-Assisted (faculty-finalized)', $aiAssisted),
            $this->kv('AI Suggestion vs Final Grade MAE', $this->na($grading['mae'] ?? null, '', 'Not evaluated')),
            $this->kv('Exact Agreement Rate', $this->na($grading['exact_agreement_rate'] ?? null, '%', 'Not evaluated')),
        ];
        $warnings = [];
        if ($totalResponses === 0) {
            $warnings[] = 'No finalized faculty grades exist for the selected scope.';
        }
        if ($ctx->assessmentIds !== [] && count($ids) < count($ctx->assessmentIds)) {
            $warnings[] = (count($ctx->assessmentIds) - count($ids)) . ' assessment(s) are excluded because you are not authorized to view their student data.';
        }
        $methods = [
            ['method' => 'FACULTY', 'responses' => $totalResponses - $aiAssisted, 'share' => $totalResponses ? round(($totalResponses - $aiAssisted) / $totalResponses * 100, 1) : null, 'meaning' => 'Graded by faculty without an AI suggestion'],
            ['method' => 'AI_ASSISTED', 'responses' => $aiAssisted, 'share' => $totalResponses ? round($aiAssisted / $totalResponses * 100, 1) : null, 'meaning' => 'AI suggestion reviewed (accepted, modified or rejected) and finalized by faculty'],
        ];
        $decisions = [
            ['decision' => AiGradingResult::DECISION_ACCEPTED, 'count' => $grading['faculty_accepted'] ?? 0],
            ['decision' => AiGradingResult::DECISION_MODIFIED, 'count' => $grading['faculty_modified'] ?? 0],
            ['decision' => AiGradingResult::DECISION_REJECTED, 'count' => $grading['faculty_rejected'] ?? 0],
        ];

        return $this->document($ctx, $summary, [$this->section('note', 'Authority', [], 'Only finalized faculty grades are authoritative. AI assistance is an interaction signal; the final grade is always the faculty decision.'), $this->section('privacy', 'Privacy', [], config('institutional_reports.sensitive_footer'))], [
            $this->table('questions', 'Question Grading (finalized)', ['assessment' => 'Assessment', 'question' => 'Question', 'max_marks' => 'Max Marks', 'responses' => 'Responses', 'average_marks' => 'Average Marks', 'average_percentage' => 'Average %', 'minimum_marks' => 'Min', 'maximum_marks' => 'Max', 'ai_assisted_responses' => 'AI-Assisted', 'faculty_only_responses' => 'Faculty-Only'], $questionRows),
            $this->table('methods', 'Grading Method Distribution', ['method' => 'Method', 'responses' => 'Responses', 'share' => 'Share %', 'meaning' => 'Meaning'], $totalResponses ? $methods : []),
            $this->table('decisions', 'Faculty Decisions on AI Suggestions', ['decision' => 'Decision', 'count' => 'Count'], $aiAssisted ? $decisions : []),
        ], $warnings);
    }
}
