<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 40: read-only academic data integrity check. Reports orphaned rows, dangling references,
 * inconsistent totals and traceability gaps. It NEVER modifies data — fixing is a human decision.
 *
 *   php artisan facultylens:integrity-check [--json] [--fail-on-issues]
 */
class IntegrityCheckCommand extends Command
{
    protected $signature = 'facultylens:integrity-check {--json : Machine-readable output} {--fail-on-issues : Exit 1 when any issue is found}';

    protected $description = 'Detect orphaned records, invalid references and inconsistent academic data (read-only)';

    public function handle(): int
    {
        $checks = $this->checks();
        $results = [];
        foreach ($checks as $key => [$label, $severity, $query]) {
            try {
                $count = (int) $query();
                $results[] = ['check' => $key, 'label' => $label, 'severity' => $severity, 'count' => $count, 'status' => $count === 0 ? 'ok' : 'issue'];
            } catch (\Throwable $e) {
                $results[] = ['check' => $key, 'label' => $label, 'severity' => $severity, 'count' => null, 'status' => 'skipped', 'reason' => class_basename($e)];
            }
        }
        $issues = collect($results)->where('status', 'issue');

        if ($this->option('json')) {
            $this->line(json_encode(['checked_at' => now()->toISOString(), 'checks' => $results, 'issues' => $issues->count()], JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Severity', 'Count', 'Status'], array_map(fn ($r) => [$r['label'], $r['severity'], $r['count'] ?? '—', strtoupper($r['status']).(isset($r['reason']) ? " ({$r['reason']})" : '')], $results));
            $issues->isEmpty() ? $this->info('No integrity issues detected. No data was modified.') : $this->warn("{$issues->count()} check(s) reported issues. Nothing was modified — review before any correction.");
        }

        return $this->option('fail-on-issues') && $issues->isNotEmpty() ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, array{0:string,1:string,2:callable}> */
    protected function checks(): array
    {
        $orphans = fn (string $table, string $fk, string $parent) => fn () => DB::table($table)->whereNotNull($fk)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($parent)->whereColumn("{$parent}.id", "{$table}.{$fk}"))->count();
        $checks = [
            'courses_without_owner' => ['Courses whose owner user is missing', 'high', $orphans('courses', 'user_id', 'users')],
            'learning_outcomes_without_course' => ['Learning outcomes without a course', 'high', $orphans('learning_outcomes', 'course_id', 'courses')],
            'assessments_without_course' => ['Assessments without a course', 'high', $orphans('assessments', 'course_id', 'courses')],
            'questions_without_assessment' => ['Questions without an assessment', 'high', $orphans('questions', 'assessment_id', 'assessments')],
            'questions_with_invalid_lo' => ['Questions mapped to a missing learning outcome', 'medium', $orphans('questions', 'learning_outcome_id', 'learning_outcomes')],
            'questions_lo_from_other_course' => ['Questions mapped to a learning outcome of another course', 'high', fn () => DB::table('questions')->join('assessments', 'assessments.id', '=', 'questions.assessment_id')->join('learning_outcomes', 'learning_outcomes.id', '=', 'questions.learning_outcome_id')->whereColumn('learning_outcomes.course_id', '!=', 'assessments.course_id')->count()],
            'questions_negative_marks' => ['Questions with negative marks', 'medium', fn () => DB::table('questions')->where('marks', '<', 0)->count()],
            'analysis_without_assessment' => ['Analysis reports without an assessment', 'high', $orphans('analysis_reports', 'assessment_id', 'assessments')],
            'analysis_with_missing_version' => ['Analysis reports pointing to a missing assessment version', 'medium', $orphans('analysis_reports', 'assessment_version_id', 'assessment_versions')],
            'multiple_current_analyses' => ['Assessments with more than one current analysis', 'medium', fn () => DB::table('analysis_reports')->where('is_current', true)->select('assessment_id')->groupBy('assessment_id')->havingRaw('COUNT(*) > 1')->get()->count()],
            'recommendations_without_analysis' => ['Recommendations without an analysis report', 'medium', $orphans('recommendations', 'analysis_report_id', 'analysis_reports')],
            'rubrics_without_question' => ['Rubrics without a question', 'medium', $orphans('rubrics', 'question_id', 'questions')],
            'rubric_criteria_without_rubric' => ['Rubric criteria without a rubric', 'medium', $orphans('rubric_criteria', 'rubric_id', 'rubrics')],
            'submissions_without_assessment' => ['Student submissions without an assessment', 'high', $orphans('student_submissions', 'assessment_id', 'assessments')],
            'submissions_without_student' => ['Student submissions without a student', 'high', $orphans('student_submissions', 'student_id', 'students')],
            'submissions_with_missing_version' => ['Student submissions pointing to a missing assessment version', 'medium', $orphans('student_submissions', 'assessment_version_id', 'assessment_versions')],
            'answers_without_submission' => ['Student answers without a submission', 'high', $orphans('student_answers', 'student_submission_id', 'student_submissions')],
            'answers_without_question' => ['Student answers without a question', 'high', $orphans('student_answers', 'question_id', 'questions')],
            'answers_question_from_other_assessment' => ['Student answers whose question belongs to another assessment', 'high', fn () => DB::table('student_answers')->join('student_submissions', 'student_submissions.id', '=', 'student_answers.student_submission_id')->join('questions', 'questions.id', '=', 'student_answers.question_id')->whereColumn('questions.assessment_id', '!=', 'student_submissions.assessment_id')->count()],
            'answers_marks_above_max' => ['Student answers awarded more than the question marks', 'high', fn () => DB::table('student_answers')->join('questions', 'questions.id', '=', 'student_answers.question_id')->whereNotNull('student_answers.awarded_marks')->whereColumn('student_answers.awarded_marks', '>', 'questions.marks')->count()],
            'answers_negative_marks' => ['Student answers with negative awarded marks', 'medium', fn () => DB::table('student_answers')->where('awarded_marks', '<', 0)->count()],
            'ai_grading_without_answer' => ['AI grading results without a student answer', 'medium', $orphans('ai_grading_results', 'student_answer_id', 'student_answers')],
            'finalized_submissions_with_unreviewed_answers' => ['Finalized submissions that still contain unreviewed answers', 'medium', fn () => DB::table('student_submissions')->whereIn('grading_status', (array) config('performance.finalized_grading_statuses', ['FACULTY_REVIEWED', 'FINALIZED']))->whereExists(fn ($q) => $q->select(DB::raw(1))->from('student_answers')->whereColumn('student_answers.student_submission_id', 'student_submissions.id')->where('student_answers.answer_status', '!=', 'REVIEWED'))->count()],
            'performance_runs_without_assessment' => ['Performance analysis runs without an assessment', 'medium', $orphans('performance_analysis_runs', 'assessment_id', 'assessments')],
            'blueprints_without_assessment' => ['Blueprints without an assessment', 'high', $orphans('assessment_blueprints', 'assessment_id', 'assessments')],
            'blueprint_section_marks_mismatch' => ['Blueprints whose section marks do not sum to the blueprint total', 'medium', fn () => DB::table('assessment_blueprints')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('assessment_blueprint_sections')->whereColumn('assessment_blueprint_sections.blueprint_id', 'assessment_blueprints.id'))->whereRaw('ABS(total_marks - (SELECT COALESCE(SUM(total_marks),0) FROM assessment_blueprint_sections s WHERE s.blueprint_id = assessment_blueprints.id)) > 0.01')->count()],
            'multiple_current_blueprints' => ['Assessments with more than one current blueprint', 'medium', fn () => DB::table('assessment_blueprints')->where('is_current', true)->select('assessment_id')->groupBy('assessment_id')->havingRaw('COUNT(*) > 1')->get()->count()],
            'versions_without_assessment' => ['Assessment versions without an assessment', 'high', $orphans('assessment_versions', 'assessment_id', 'assessments')],
            'version_questions_without_version' => ['Version question snapshots without a version', 'high', $orphans('assessment_version_questions', 'assessment_version_id', 'assessment_versions')],
            'version_totals_mismatch' => ['Assessment versions whose stored totals differ from their question snapshots', 'medium', fn () => DB::table('assessment_versions')->whereRaw('ABS(total_marks - (SELECT COALESCE(SUM(marks),0) FROM assessment_version_questions q WHERE q.assessment_version_id = assessment_versions.id)) > 0.01 OR question_count != (SELECT COUNT(*) FROM assessment_version_questions q2 WHERE q2.assessment_version_id = assessment_versions.id)')->count()],
            'duplicate_version_numbers' => ['Assessments with duplicate version numbers', 'high', fn () => DB::table('assessment_versions')->select('assessment_id', 'version_number')->groupBy('assessment_id', 'version_number')->havingRaw('COUNT(*) > 1')->get()->count()],
            'finalized_assessments_without_version' => ['Published/completed assessments with student submissions but no version', 'low', fn () => DB::table('assessments')->whereIn('status', ['published', 'completed'])->whereExists(fn ($q) => $q->select(DB::raw(1))->from('student_submissions')->whereColumn('student_submissions.assessment_id', 'assessments.id'))->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('assessment_versions')->whereColumn('assessment_versions.assessment_id', 'assessments.id'))->count()],
            'reports_with_missing_version' => ['Institutional reports pointing to a missing assessment version', 'medium', fn () => DB::table('institutional_reports')->where('scope_type', 'ASSESSMENT_VERSION')->where(fn ($q) => $q->whereNull('assessment_version_id')->orWhereNotExists(fn ($s) => $s->select(DB::raw(1))->from('assessment_versions')->whereColumn('assessment_versions.id', 'institutional_reports.assessment_version_id')))->count()],
            'reports_without_creator' => ['Institutional reports whose creator is missing', 'low', $orphans('institutional_reports', 'created_by', 'users')],
            'completed_reports_missing_file' => ['Completed, unexpired reports whose file path is empty', 'medium', fn () => DB::table('institutional_reports')->where('status', 'COMPLETED')->whereNull('file_deleted_at')->whereNull('file_path')->count()],
            'co_po_mappings_without_lo' => ['CO/PO mappings without a learning outcome', 'medium', $orphans('co_po_mappings', 'learning_outcome_id', 'learning_outcomes')],
            'co_po_mappings_without_po' => ['CO/PO mappings without a program outcome', 'medium', $orphans('co_po_mappings', 'program_outcome_id', 'program_outcomes')],
            'documents_without_course' => ['Documents without a course', 'medium', $orphans('document_processings', 'course_id', 'courses')],
            'collaborators_without_user' => ['Course collaborators without a user', 'medium', $orphans('course_collaborators', 'user_id', 'users')],
        ];

        // Skip checks whose tables are absent in this deployment
        return array_filter($checks, function ($c, $key) {
            $table = match (true) {
                str_starts_with($key, 'documents') => 'document_processings',
                str_starts_with($key, 'collaborators') => 'course_collaborators',
                str_starts_with($key, 'co_po') => 'co_po_mappings',
                str_starts_with($key, 'reports') || str_starts_with($key, 'completed_reports') => 'institutional_reports',
                str_starts_with($key, 'ai_grading') => 'ai_grading_results',
                str_starts_with($key, 'performance') => 'performance_analysis_runs',
                default => null,
            };

            return $table === null || Schema::hasTable($table);
        }, ARRAY_FILTER_USE_BOTH);
    }
}
