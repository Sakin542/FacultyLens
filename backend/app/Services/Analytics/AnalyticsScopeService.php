<?php

namespace App\Services\Analytics;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\CoPoMappingAnalysisRun;
use App\Models\Course;
use App\Models\PerformanceAnalysisRun;
use App\Models\Question;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\CourseAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * STEP 36: resolves which courses/assessments an analytics request may aggregate over.
 * Every analytics query starts from this scope so Faculty A can never aggregate Faculty B's private data.
 */
class AnalyticsScopeService
{
    public const FILTER_KEYS = ['course_id', 'assessment_id', 'semester', 'academic_year', 'assessment_type', 'start_date', 'end_date'];

    public function __construct(protected CourseAccessService $access) {}

    /** Normalised filters (nulls removed, ids cast). */
    public function normalize(array $filters): array
    {
        $out = [];
        foreach (self::FILTER_KEYS as $k) {
            $v = $filters[$k] ?? null;
            if ($v === null || $v === '' || $v === 'all') {
                continue;
            }
            $out[$k] = in_array($k, ['course_id', 'assessment_id'], true) ? (int) $v : (string) $v;
        }

        return $out;
    }

    /** Course ids the user may view analytics for, narrowed by course-level filters. */
    public function courseIds(User $user, array $filters): array
    {
        $q = $this->access->accessibleCourses($user)->select('courses.id');
        if (!empty($filters['course_id'])) {
            $q->where('courses.id', $filters['course_id']);
        }
        if (!empty($filters['semester'])) {
            $q->where('courses.semester', $filters['semester']);
        }
        if (!empty($filters['academic_year'])) {
            $q->where('courses.academic_year', $filters['academic_year']);
        }

        return $q->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
    }

    /** All accessible course records sharing a course code (historical terms of the same course). */
    public function accessibleCourseIdsByCode(User $user, string $courseCode): array
    {
        return $this->access->accessibleCourses($user)->where('courses.course_code', $courseCode)->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Subset of course ids where the user may see student-level aggregates (OWNER/EDITOR, or admin). */
    public function studentDataCourseIds(User $user, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }
        if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
            return $courseIds;
        }

        return Course::whereIn('id', $courseIds)->get()
            ->filter(fn (Course $c) => $this->access->can($user, $c, 'view_student_data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Assessment query narrowed by assessment-level filters. */
    public function assessmentsQuery(array $courseIds, array $filters): Builder
    {
        $q = Assessment::query()->whereIn('assessments.course_id', $courseIds ?: [-1]);
        if (!empty($filters['assessment_id'])) {
            $q->where('assessments.id', $filters['assessment_id']);
        }
        if (!empty($filters['assessment_type'])) {
            $q->where('assessments.type', $filters['assessment_type']);
        }
        if (!empty($filters['start_date'])) {
            $q->whereRaw('COALESCE(assessments.assessment_date, assessments.created_at) >= ?', [$filters['start_date']]);
        }
        if (!empty($filters['end_date'])) {
            $q->whereRaw('COALESCE(assessments.assessment_date, assessments.created_at) <= ?', [$filters['end_date'] . ' 23:59:59']);
        }

        return $q;
    }

    public function assessmentIds(array $courseIds, array $filters): array
    {
        if ($courseIds === []) {
            return [];
        }

        return $this->assessmentsQuery($courseIds, $filters)->pluck('assessments.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Current completed STEP 13 analysis reports for the assessments in scope. */
    public function currentReportsQuery(array $assessmentIds): Builder
    {
        return AnalysisReport::query()->whereIn('analysis_reports.assessment_id', $assessmentIds ?: [-1])->where('analysis_reports.is_current', true)->where('analysis_reports.analysis_status', 'completed');
    }

    public function currentPerformanceRunsQuery(array $assessmentIds): Builder
    {
        return PerformanceAnalysisRun::query()->whereIn('performance_analysis_runs.assessment_id', $assessmentIds ?: [-1])->where('performance_analysis_runs.is_current', true)->where('performance_analysis_runs.status', PerformanceAnalysisRun::STATUS_COMPLETED);
    }

    /**
     * Cheap fingerprint of the newest change in the scoped data; part of the cache key so cached
     * analytics can never be served after a question, grade, analysis or mapping changes.
     */
    public function dataVersion(array $courseIds, array $assessmentIds): string
    {
        if ($courseIds === []) {
            return 'empty';
        }
        $a = $assessmentIds ?: [-1];
        // updated_at has second precision, so content-sensitive aggregates are added for the fields analytics depend on
        $parts = [
            Assessment::whereIn('course_id', $courseIds)->selectRaw('COUNT(*) c, MAX(updated_at) m')->first(),
            Question::whereIn('assessment_id', $a)->selectRaw("COUNT(*) c, MAX(updated_at) m, SUM(marks) s1, SUM(COALESCE(learning_outcome_id, 0)) s2,
                SUM(CASE LOWER(COALESCE(difficulty_level, ai_difficulty_level)) WHEN 'easy' THEN 1 WHEN 'medium' THEN 10 WHEN 'hard' THEN 100 ELSE 0 END) s3,
                SUM(CASE LOWER(COALESCE(cognitive_level, ai_cognitive_level)) WHEN 'remember' THEN 1 WHEN 'understand' THEN 10 WHEN 'apply' THEN 100 WHEN 'analyze' THEN 1000 WHEN 'evaluate' THEN 10000 WHEN 'create' THEN 100000 ELSE 0 END) s4")->first(),
            AnalysisReport::whereIn('assessment_id', $a)->selectRaw('COUNT(*) c, MAX(updated_at) m, SUM(CASE WHEN is_current THEN id ELSE 0 END) s1')->first(),
            PerformanceAnalysisRun::whereIn('assessment_id', $a)->selectRaw('COUNT(*) c, MAX(updated_at) m, SUM(CASE WHEN is_current THEN id ELSE 0 END) s1')->first(),
            StudentSubmission::whereIn('assessment_id', $a)->selectRaw('COUNT(*) c, MAX(updated_at) m, SUM(CASE grading_status WHEN \'FINALIZED\' THEN 1 WHEN \'FACULTY_REVIEWED\' THEN 1 ELSE 0 END) s1')->first(),
            DB::table('student_answers')->whereIn('student_submission_id', fn ($q) => $q->select('id')->from('student_submissions')->whereIn('assessment_id', $a))->selectRaw('COUNT(*) c, MAX(updated_at) m, SUM(COALESCE(awarded_marks, 0)) s1')->first(),
            CoPoMappingAnalysisRun::whereIn('course_id', $courseIds)->selectRaw('COUNT(*) c, MAX(updated_at) m')->first(),
            DB::table('co_po_mappings')->whereIn('course_id', $courseIds)->selectRaw('COUNT(*) c, MAX(updated_at) m, SUM(mapping_level) s1')->first(),
            DB::table('recommendations')->whereIn('analysis_report_id', fn ($q) => $q->select('id')->from('analysis_reports')->whereIn('assessment_id', $a))->selectRaw('COUNT(*) c, MAX(updated_at) m')->first(),
            DB::table('ai_evaluation_runs')->selectRaw('COUNT(*) c, MAX(updated_at) m')->first(),
        ];

        return sha1(implode('|', array_map(fn ($r) => implode(':', [(int) ($r->c ?? 0), (string) ($r->m ?? ''), (string) ($r->s1 ?? ''), (string) ($r->s2 ?? ''), (string) ($r->s3 ?? ''), (string) ($r->s4 ?? '')]), $parts)));
    }

    /** Filter options limited to what the user can access. */
    public function filterOptions(User $user): array
    {
        $courses = $this->access->accessibleCourses($user)->orderBy('courses.course_code')->get(['courses.id', 'courses.course_code', 'courses.course_name', 'courses.semester', 'courses.academic_year']);
        $ids = $courses->pluck('id')->all();
        $assessments = Assessment::whereIn('course_id', $ids ?: [-1])->orderBy('title')->get(['id', 'course_id', 'title', 'type', 'assessment_date']);

        return [
            'courses' => $courses->map(fn ($c) => ['id' => $c->id, 'code' => $c->course_code, 'name' => $c->course_name, 'semester' => $c->semester, 'academic_year' => $c->academic_year])->values(),
            'assessments' => $assessments->map(fn ($a) => ['id' => $a->id, 'course_id' => $a->course_id, 'title' => $a->title, 'type' => $a->type, 'date' => optional($a->assessment_date)->toDateString()])->values(),
            'semesters' => $courses->pluck('semester')->filter()->unique()->sort()->values(),
            'academic_years' => $courses->pluck('academic_year')->filter()->unique()->sortDesc()->values(),
            'assessment_types' => $assessments->pluck('type')->filter()->unique()->sort()->values(),
        ];
    }
}
