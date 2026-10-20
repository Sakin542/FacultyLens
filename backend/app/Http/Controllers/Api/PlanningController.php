<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use App\Services\Planning\CourseArchiveService;
use App\Services\Planning\CourseMetricsService;
use App\Services\Planning\CourseQualityTrendService;
use App\Services\Planning\ObePackService;
use App\Services\Planning\WorkloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Course quality across semesters, workload, OBE accreditation pack, course file archive and the department view. */
class PlanningController extends Controller
{
    public function __construct(protected CourseAccessService $access, protected AuditLogService $audit) {}

    public function qualityTrend(Request $request, Course $course, CourseQualityTrendService $trends): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'view_analysis');

        return response()->json(['status' => 'success', 'data' => $trends->trend($course, $request->user())]);
    }

    public function workload(Request $request, WorkloadService $workload): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $workload->forUser($request->user())]);
    }

    public function obe(Request $request, Course $course, ObePackService $obe): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'view_analysis');
        $data = $obe->data($course, false);

        return response()->json(['status' => 'success', 'data' => [
            'course' => ['id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name, 'term' => CourseMetricsService::termLabel($course)],
            'co_target' => $data['co_target'],
            'po_target' => $data['po_target'],
            'co_attainment' => $data['co_attainment'],
            'po_attainment' => $data['po_attainment'],
            'program_outcomes' => $data['program_outcomes']->map(fn ($p) => $p->only(['id', 'code', 'title']))->values(),
            'matrix' => $data['matrix'],
            'assessments' => collect($data['evidence'])->map(fn ($e) => ['id' => $e['assessment']->id, 'title' => $e['assessment']->title, 'questions' => count($e['questions']), 'mapped' => collect($e['questions'])->whereNotNull('co')->count()])->values(),
        ]]);
    }

    public function obePack(Request $request, Course $course, ObePackService $obe): Response
    {
        $this->authorizeCourse($request, $course, 'view_analysis');
        $samples = $this->access->can($request->user(), $course, 'view_student_data');
        $bytes = $obe->zip($course, $samples);
        $this->audit->log('OBE_PACK_EXPORTED', $course, $course->id, ['samples' => $samples], $request->user());

        return $this->download($bytes, Str::slug($course->course_code.' '.CourseMetricsService::termLabel($course).' obe pack').'.zip', 'application/zip');
    }

    public function archive(Request $request, Course $course, CourseArchiveService $archive): Response
    {
        $this->authorizeCourse($request, $course, 'view_documents');
        $studentData = $this->access->can($request->user(), $course, 'view_student_data');
        $result = $archive->build($course, $studentData);
        $this->audit->log('COURSE_ARCHIVE_EXPORTED', $course, $course->id, ['files' => count($result['manifest']), 'student_data' => $studentData], $request->user());

        return $this->download($result['zip'], $result['filename'], 'application/zip');
    }

    /** Department head (or admin) overview: every course in the department with its latest metrics and faculty load. */
    public function department(Request $request, CourseMetricsService $metrics, WorkloadService $workload): JsonResponse
    {
        $user = $request->user();
        if (!$user->isDepartmentHead() && !$user->isAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'The department view is available to department heads.'], 403);
        }
        $department = $user->isAdmin() && $request->filled('department') ? (string) $request->query('department') : (string) $user->department;
        if (trim($department) === '') {
            return response()->json(['status' => 'error', 'message' => 'Your profile has no department. Ask the administrator to set it.'], 422);
        }

        $normalized = CourseAccessService::normalizeDepartment($department);
        $faculty = User::query()->whereRaw('LOWER(TRIM(department)) = ?', [$normalized])->orderBy('name')->get();
        $courses = Course::query()->whereIn('user_id', $faculty->pluck('id'))->with('user:id,name')->get()
            ->sortBy(fn ($c) => sprintf('%s-%04d-%d', $c->course_code, (int) $c->academic_year, CourseMetricsService::semesterOrder($c->semester)));

        $rows = $courses->map(function (Course $c) use ($metrics) {
            $m = $metrics->metrics($c);

            return $m + ['instructor' => $c->user?->only(['id', 'name']), 'flags' => $this->courseFlags($m)];
        })->values();

        $facultyRows = $faculty->map(function (User $f) use ($workload) {
            $w = $workload->forUser($f);

            return [
                'id' => $f->id,
                'name' => $f->name,
                'designation' => $f->designation,
                'role' => $f->role ?? 'FACULTY',
                'active_courses' => $w['totals']['active_courses'],
                'contact_hours_per_week' => $w['totals']['contact_hours_per_week'],
                'ungraded_answers' => $w['totals']['ungraded_answers'],
                'estimated_hours_this_week' => $w['estimate_hours_this_week']['total'],
            ];
        })->values();

        $avg = fn (string $key) => ($vals = $rows->pluck($key)->filter(fn ($v) => $v !== null))->isEmpty() ? null : round($vals->avg(), 1);

        return response()->json(['status' => 'success', 'data' => [
            'department' => $department,
            'summary' => [
                'faculty' => $faculty->count(),
                'courses' => $rows->count(),
                'mean_higher_order_percent' => $avg('higher_order_percent'),
                'mean_co_coverage_percent' => $avg('co_coverage_percent'),
                'mean_class_average_percent' => $avg('class_average_percent'),
                'courses_needing_attention' => $rows->filter(fn ($r) => $r['flags'] !== [])->count(),
            ],
            'courses' => $rows,
            'faculty' => $facultyRows,
        ]]);
    }

    /** @return list<string> */
    protected function courseFlags(array $m): array
    {
        $flags = [];
        if ($m['questions'] === 0) {
            return ['No assessment questions recorded yet'];
        }
        if ($m['co_coverage_percent'] !== null && $m['co_coverage_percent'] < 100) {
            $flags[] = 'Some course outcomes are not assessed';
        }
        if ($m['questions'] > 0 && $m['higher_order_percent'] < 20) {
            $flags[] = 'Few higher-order questions';
        }
        if ($m['co_attained_percent'] !== null && $m['co_attained_percent'] < 50) {
            $flags[] = 'Most course outcomes not attained';
        }
        if ($m['flagged_questions'] > 0) {
            $flags[] = $m['flagged_questions'].' question(s) flagged by item analysis';
        }

        return $flags;
    }

    protected function download(string $bytes, string $filename, string $type): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'no-store',
        ]);
    }

    protected function authorizeCourse(Request $request, Course $course, string $ability): void
    {
        abort_unless($this->access->can($request->user(), $course, $ability), 403, 'Unauthorized access to this course.');
    }
}
