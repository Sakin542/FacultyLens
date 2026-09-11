<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\Program;
use App\Models\User;
use App\Services\Analytics\AnalyticsScopeService;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportValidationException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * STEP 39: decides which report types/scopes a user may run and resolves a request into an
 * authorized ReportContext. Reuses the STEP 34 course matrix (view / view_student_data) for
 * faculty scopes; DEPARTMENT and INSTITUTION scopes require the configured roles.
 * The client's role/scope claims are never trusted — everything is resolved here.
 */
class ReportAuthorizationService
{
    public function __construct(protected CourseAccessService $access, protected AnalyticsScopeService $scope) {}

    /** @return string[] */
    public function allowedScopes(User $user): array
    {
        $scopes = ['FACULTY', 'COURSE', 'ASSESSMENT', 'ASSESSMENT_VERSION'];
        if ($this->hasRole($user, 'department_roles')) {
            $scopes[] = 'DEPARTMENT';
        }
        if ($this->hasRole($user, 'institution_roles')) {
            $scopes[] = 'INSTITUTION';
        }

        return $scopes;
    }

    /** Report types the user may run (at least one allowed scope), with their allowed scopes narrowed. */
    public function availableTypes(User $user): array
    {
        $allowed = $this->allowedScopes($user);
        $out = [];
        foreach ((array) config('institutional_reports.types') as $key => $def) {
            $scopes = array_values(array_intersect($def['scopes'], $allowed));
            if ($scopes === []) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $def['label'], 'description' => $def['description'], 'scopes' => $scopes,
                'student_data' => (bool) $def['student_data'], 'formats' => (array) config('institutional_reports.formats')];
        }

        return $out;
    }

    /** Filter keys that apply to a report type + scope, and the options the user may choose from. */
    public function filterOptions(User $user, ?string $type = null, ?string $scope = null): array
    {
        $options = $this->scope->filterOptions($user);
        $versions = AssessmentVersion::query()->whereIn('assessment_id', $options['assessments']->pluck('id')->all() ?: [-1])
            ->orderBy('assessment_id')->orderByDesc('version_number')
            ->get(['id', 'assessment_id', 'version_number', 'version_label', 'status', 'total_marks', 'question_count'])
            ->map(fn ($v) => ['id' => $v->id, 'assessment_id' => $v->assessment_id, 'version_number' => $v->version_number, 'version_label' => $v->version_label, 'status' => $v->status, 'total_marks' => (float) $v->total_marks, 'question_count' => $v->question_count])->values();
        $out = ['courses' => $options['courses'], 'assessments' => $options['assessments'], 'assessment_versions' => $versions,
            'semesters' => $options['semesters'], 'academic_years' => $options['academic_years'], 'assessment_types' => $options['assessment_types'],
            'statuses' => ['draft', 'published', 'completed'], 'departments' => [], 'programs' => []];
        if (in_array('DEPARTMENT', $this->allowedScopes($user), true)) {
            $out['departments'] = User::query()->whereNotNull('department')->where('department', '!=', '')->distinct()->orderBy('department')->pluck('department')->values();
        }
        if (in_array('INSTITUTION', $this->allowedScopes($user), true)) {
            $out['programs'] = Program::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->values();
        }
        $applicable = $scope ? (array) (config('institutional_reports.scope_filters.' . $scope) ?? []) : (array) config('institutional_reports.filter_keys');
        if ($type && !(config('institutional_reports.types.' . $type . '.scopes') ?? [])) {
            $applicable = [];
        }

        return ['applicable' => array_values($applicable), 'options' => $out];
    }

    /**
     * Resolve + authorize a request. Throws AuthorizationException (403) or ReportValidationException (422).
     */
    public function resolve(User $user, array $input): ReportContext
    {
        $type = strtoupper((string) ($input['report_type'] ?? ''));
        $def = config('institutional_reports.types.' . $type);
        if (!$def) {
            throw new ReportValidationException('The selected report type is not supported.', ['report_type' => ['Unsupported report type.']]);
        }
        $scope = strtoupper((string) ($input['scope_type'] ?? ''));
        if (!in_array($scope, $def['scopes'], true)) {
            throw new ReportValidationException('The selected scope is not available for this report type.', ['scope_type' => ['Scope not available for this report type.']]);
        }
        if (!in_array($scope, $this->allowedScopes($user), true)) {
            throw new AuthorizationException('You are not authorized to generate this report.');
        }

        $filters = $this->normalizeFilters($input['filters'] ?? [], $scope);
        $this->validateDateRange($filters);

        $course = null;
        $assessment = null;
        $version = null;
        $department = null;
        $programId = null;

        switch ($scope) {
            case 'ASSESSMENT_VERSION':
            case 'ASSESSMENT':
                $assessment = Assessment::with('course')->find((int) ($filters['assessment_id'] ?? 0));
                if (!$assessment || !$assessment->course) {
                    throw new ReportValidationException('The selected assessment is unavailable.', ['assessment_id' => ['Select an assessment.']]);
                }
                if (!empty($filters['course_id']) && (int) $filters['course_id'] !== (int) $assessment->course_id) {
                    throw new ReportValidationException('The selected assessment does not belong to the selected course.', ['assessment_id' => ['Assessment does not belong to the selected course.']]);
                }
                $course = $assessment->course;
                $this->authorizeCourse($user, $course);
                $filters['course_id'] = $course->id;
                if ($scope === 'ASSESSMENT_VERSION') {
                    $version = AssessmentVersion::find((int) ($filters['assessment_version_id'] ?? 0));
                    if (!$version || (int) $version->assessment_id !== (int) $assessment->id) {
                        throw new ReportValidationException('The selected assessment version is unavailable.', ['assessment_version_id' => ['Select a version of this assessment.']]);
                    }
                }
                $courseIds = [$course->id];
                $assessmentIds = [$assessment->id];
                break;

            case 'COURSE':
                $course = Course::find((int) ($filters['course_id'] ?? 0));
                if (!$course) {
                    throw new ReportValidationException('The selected course is unavailable.', ['course_id' => ['Select a course.']]);
                }
                $this->authorizeCourse($user, $course);
                $courseIds = [$course->id];
                $assessmentIds = $this->scope->assessmentIds($courseIds, $this->assessmentFilters($filters));
                break;

            case 'DEPARTMENT':
                $department = trim((string) ($filters['department'] ?? ''));
                if ($department === '') {
                    throw new ReportValidationException('Select a department for a department-scoped report.', ['department' => ['Department is required.']]);
                }
                $q = Course::query()->whereIn('courses.user_id', User::query()->where('department', $department)->select('id'));
                $courseIds = $this->applyCourseFilters($q, $filters)->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
                $assessmentIds = $this->scope->assessmentIds($courseIds, $this->assessmentFilters($filters));
                break;

            case 'INSTITUTION':
                $q = Course::query();
                if (!empty($filters['program_id'])) {
                    $programId = (int) $filters['program_id'];
                    $q->where('courses.program_id', $programId);
                }
                $courseIds = $this->applyCourseFilters($q, $filters)->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
                $assessmentIds = $this->scope->assessmentIds($courseIds, $this->assessmentFilters($filters));
                break;

            default: // FACULTY — own + collaborating courses only
                $courseIds = $this->scope->courseIds($user, ['semester' => $filters['semester'] ?? null, 'academic_year' => $filters['academic_year'] ?? null]);
                $assessmentIds = $this->scope->assessmentIds($courseIds, $this->assessmentFilters($filters));
        }

        if (!empty($filters['status']) && $assessmentIds !== []) {
            $assessmentIds = Assessment::whereIn('id', $assessmentIds)->where('status', $filters['status'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        // Student-data reports: course-level ability (OWNER/EDITOR) or admin; nothing else may see aggregates.
        $studentIds = [];
        if ($def['student_data']) {
            $studentCourseIds = $this->scope->studentDataCourseIds($user, $courseIds);
            if ($courseIds !== [] && $studentCourseIds === []) {
                throw new AuthorizationException('You are not authorized to generate this report.');
            }
            $studentIds = $studentCourseIds === [] ? [] : array_values(array_intersect($assessmentIds, Assessment::whereIn('course_id', $studentCourseIds)->pluck('id')->map(fn ($id) => (int) $id)->all()));
        }

        return new ReportContext($user, $type, $def, $scope, $filters, $courseIds, $assessmentIds, $studentIds, $course, $assessment, $version, $department, $programId);
    }

    protected function authorizeCourse(User $user, Course $course): void
    {
        if (!$this->access->can($user, $course, 'view')) {
            throw new AuthorizationException('You are not authorized to generate this report.');
        }
    }

    /** Keep only filters applicable to the scope; drop blanks; cast ids. */
    protected function normalizeFilters(array $filters, string $scope): array
    {
        $applicable = (array) (config('institutional_reports.scope_filters.' . $scope) ?? []);
        $out = [];
        foreach ($applicable as $k) {
            $v = $filters[$k] ?? null;
            if ($v === null || $v === '' || $v === 'all') {
                continue;
            }
            $out[$k] = in_array($k, ['course_id', 'assessment_id', 'assessment_version_id', 'program_id'], true) ? (int) $v : trim((string) $v);
        }

        return $out;
    }

    protected function validateDateRange(array $filters): void
    {
        if (!empty($filters['start_date']) && !empty($filters['end_date']) && $filters['start_date'] > $filters['end_date']) {
            throw new ReportValidationException('The date range is invalid: the start date is after the end date.', ['start_date' => ['Start date must be before the end date.']]);
        }
    }

    protected function assessmentFilters(array $filters): array
    {
        return array_intersect_key($filters, array_flip(['assessment_type', 'start_date', 'end_date']));
    }

    protected function applyCourseFilters($q, array $filters)
    {
        if (!empty($filters['semester'])) {
            $q->where('courses.semester', $filters['semester']);
        }
        if (!empty($filters['academic_year'])) {
            $q->where('courses.academic_year', $filters['academic_year']);
        }

        return $q->select('courses.id');
    }

    protected function hasRole(User $user, string $configKey): bool
    {
        $role = strtoupper((string) ($user->role ?? 'FACULTY'));

        return in_array($role, array_map('strtoupper', (array) config('institutional_reports.' . $configKey, [])), true);
    }
}
