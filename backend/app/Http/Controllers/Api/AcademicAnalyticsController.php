<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\AcademicAnalyticsService;
use App\Services\Analytics\AnalyticsScopeService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * STEP 36: Academic Analytics Dashboard API. Validates filters, authorizes scope through CourseAccessService
 * (via AnalyticsScopeService) and returns standardized {status, message, data} responses.
 */
class AcademicAnalyticsController extends Controller
{
    public function __construct(
        protected AcademicAnalyticsService $analytics,
        protected AnalyticsScopeService $scope,
        protected CourseAccessService $access,
        protected AuditLogService $audit,
    ) {}

    protected function filterRules(): array
    {
        return [
            'course_id' => ['nullable', 'integer'], 'assessment_id' => ['nullable', 'integer'],
            'semester' => ['nullable', 'string', 'max:50'], 'academic_year' => ['nullable', 'string', 'max:20'],
            'assessment_type' => ['nullable', 'string', 'max:40'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'sort' => ['nullable', Rule::in(['worst', 'gap', 'number', 'co'])], 'fresh' => ['nullable', 'boolean'],
        ];
    }

    /** GET /api/analytics/overview */
    public function overview(Request $request): JsonResponse
    {
        $filters = $request->validate($this->filterRules());
        if (!empty($filters['course_id']) && !$this->canViewCourse($request, (int) $filters['course_id'])) {
            return $this->error('You do not have access to this course.', 403);
        }
        $data = $this->analytics->overview($request->user(), $filters, (bool) ($filters['fresh'] ?? false));

        return $this->ok('Analytics overview retrieved.', $data);
    }

    /** GET /api/analytics/filters */
    public function filters(Request $request): JsonResponse
    {
        return $this->ok('Filter options retrieved.', $this->scope->filterOptions($request->user()));
    }

    /** GET /api/analytics/courses/{course} */
    public function course(Request $request, Course $course): JsonResponse
    {
        if (!$this->access->can($request->user(), $course, 'view_analysis')) {
            return $this->error('You do not have access to this course.', 403);
        }
        $filters = $request->validate($this->filterRules()) + ['course_id' => $course->id];
        $filters['course_id'] = $course->id;

        return $this->ok('Course analytics retrieved.', $this->analytics->overview($request->user(), $filters, (bool) ($filters['fresh'] ?? false)));
    }

    /** GET /api/analytics/courses/{course}/{section} — performance | outcomes | ai | similarity | history */
    public function courseSection(Request $request, Course $course, string $section): JsonResponse
    {
        if (!$this->access->can($request->user(), $course, 'view_analysis')) {
            return $this->error('You do not have access to this course.', 403);
        }
        $filters = $request->validate($this->filterRules());
        $filters['course_id'] = $course->id;
        if ($section === 'history') {
            return $this->ok('Course history retrieved.', $this->analytics->courseHistory($request->user(), $course, $filters));
        }
        $overview = $this->analytics->overview($request->user(), $filters, (bool) ($filters['fresh'] ?? false));
        $sections = [
            'assessments' => ['assessments', 'assessment_quality', 'difficulty', 'cognitive', 'similarity', 'question_bank'],
            'performance' => ['performance', 'learning_gaps', 'question_performance', 'topic_performance'],
            'outcomes' => ['learning_outcomes', 'program_outcomes'],
            'ai' => ['rubrics', 'grading', 'inter_grader', 'ai_evaluation', 'recommendations'],
            'similarity' => ['similarity'],
        ];
        if (!isset($sections[$section])) {
            return $this->error('Unknown analytics section.', 404);
        }
        $data = array_intersect_key($overview, array_flip($sections[$section]));
        $data['meta'] = $overview['meta'];
        $data['scope'] = $overview['scope'];

        return $this->ok('Course analytics section retrieved.', $data);
    }

    /** GET /api/analytics/compare?assessment_ids[]=1&assessment_ids[]=2 */
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate(['assessment_ids' => ['required', 'array', 'min:2', 'max:6'], 'assessment_ids.*' => ['integer']]);
        $result = $this->analytics->compare($request->user(), $data['assessment_ids']);
        if ($result['authorized'] === 0) {
            return $this->error('You do not have access to the requested assessments.', 403);
        }

        return $this->ok('Assessment comparison retrieved.', $result);
    }

    /** GET /api/analytics/export?format=pdf|csv */
    public function export(Request $request): JsonResponse|Response
    {
        $filters = $request->validate($this->filterRules() + ['format' => ['nullable', Rule::in(['pdf', 'csv', 'json'])]]);
        if (!empty($filters['course_id']) && !$this->canViewCourse($request, (int) $filters['course_id'])) {
            return $this->error('You do not have access to this course.', 403);
        }
        $format = $filters['format'] ?? 'pdf';
        unset($filters['format']);
        $overview = $this->analytics->overview($request->user(), $filters, true);
        $this->audit->log('ANALYTICS_EXPORTED', 'AcademicAnalytics', null, ['format' => $format, 'filters' => $overview['filters'], 'course_ids' => count($overview['scope']['course_ids'])], $request->user());
        $name = 'academic-analytics-' . now()->format('Ymd-His');
        if ($format === 'json') {
            return response()->json(['status' => 'success', 'message' => 'Analytics exported.', 'data' => $overview], 200, ['Content-Disposition' => "attachment; filename=\"{$name}.json\""]);
        }
        if ($format === 'csv') {
            return response($this->analytics->toCsv($overview), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
        }
        $pdf = Pdf::loadView('reports.academic-analytics-pdf', ['a' => $overview, 'user' => $request->user()]);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
    }

    protected function canViewCourse(Request $request, int $courseId): bool
    {
        $course = Course::find($courseId);

        return $course && $this->access->can($request->user(), $course, 'view_analysis');
    }

    protected function ok(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
