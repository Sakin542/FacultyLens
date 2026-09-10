<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\PerformanceAnalysisRun;
use App\Models\Student;
use App\Services\AuditLogService;
use App\Services\PerformanceAnalysisException;
use App\Services\StudentPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * STEP 30: Student Performance / Gap Analysis.
 * Aggregate endpoints expose statistics only; the student-level endpoint is ownership-checked.
 */
class PerformanceController extends Controller
{
    public function __construct(
        protected StudentPerformanceService $service,
        protected AuditLogService $auditLogService,
    ) {}

    /** GET /api/assessments/{assessment}/performance */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->owns($request, $assessment)) {
            return $this->forbidden();
        }

        $run = $this->service->currentRun($assessment);
        if (!$run) {
            return response()->json([
                'status' => 'success',
                'data' => null,
                'meta' => $this->meta($assessment),
                'message' => 'No performance analysis has been generated for this assessment yet.',
            ]);
        }

        $data = Cache::remember(
            StudentPerformanceService::cacheKey($assessment->id, 'summary') . ':' . $run->id . ':' . $run->updated_at?->timestamp,
            (int) config('performance.cache_ttl', 600),
            fn () => $this->service->present($run, true)
        );

        $this->auditLogService->log('PERFORMANCE_REPORT_VIEWED', $run, $run->id, ['assessment_id' => $assessment->id], $request->user());

        return response()->json(['status' => 'success', 'data' => $data, 'meta' => $this->meta($assessment)]);
    }

    /** POST /api/assessments/{assessment}/performance/analyze */
    public function analyze(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->owns($request, $assessment)) {
            return $this->forbidden();
        }

        try {
            $outcome = $this->service->request($assessment, $request->user(), $request->boolean('force'));
        } catch (PerformanceAnalysisException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatus());
        }

        $run = $outcome['run'];
        $queued = $run->isActive();

        return response()->json([
            'status' => $queued ? 'processing' : 'success',
            'message' => $queued
                ? 'Performance analysis has been queued for this assessment.'
                : ($outcome['created'] ? 'Performance analysis generated from finalized grades.' : 'Performance analysis is already in progress.'),
            'data' => $this->service->present($run, !$queued),
            'meta' => $this->meta($assessment),
        ], $queued ? 202 : 200);
    }

    /** GET /api/assessments/{assessment}/performance/questions */
    public function questions(Request $request, Assessment $assessment): JsonResponse
    {
        return $this->part($request, $assessment, 'questions', fn (PerformanceAnalysisRun $run) => $this->service->presentQuestions($run->load('questionResults')));
    }

    /** GET /api/assessments/{assessment}/performance/topics */
    public function topics(Request $request, Assessment $assessment): JsonResponse
    {
        return $this->part($request, $assessment, 'topics', fn (PerformanceAnalysisRun $run) => $this->service->presentTopics($run->load('topicResults')));
    }

    /** GET /api/assessments/{assessment}/performance/learning-outcomes */
    public function learningOutcomes(Request $request, Assessment $assessment): JsonResponse
    {
        return $this->part($request, $assessment, 'learning-outcomes', fn (PerformanceAnalysisRun $run) => $this->service->presentLearningOutcomes($run->load('learningOutcomeResults')));
    }

    /** GET /api/assessments/{assessment}/performance/history */
    public function history(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->owns($request, $assessment)) {
            return $this->forbidden();
        }
        $runs = PerformanceAnalysisRun::where('assessment_id', $assessment->id)->orderByDesc('id')->limit(20)->get();

        return response()->json(['status' => 'success', 'data' => $runs->map(fn ($r) => $this->service->present($r, false))->values()]);
    }

    /**
     * GET /api/students/{student}/assessments/{assessment}/performance — authorized faculty only.
     */
    public function student(Request $request, Student $student, Assessment $assessment): JsonResponse
    {
        $user = $request->user();
        // Student identity/grades: OWNER/EDITOR only, and the student must have a submission in this assessment.
        if (!$this->owns($request, $assessment, 'view_student_data')
            || (!$user->isAdmin() && $student->created_by !== $assessment->course->user_id && !$assessment->submissions()->where('student_id', $student->id)->exists())) {
            return $this->forbidden();
        }

        $data = $this->service->studentPerformance($student, $assessment);

        $this->auditLogService->log('STUDENT_PERFORMANCE_VIEWED', $assessment, $assessment->id, [
            'student_id' => $student->id,
            'has_finalized_grades' => $data['has_finalized_grades'],
        ], $user);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    // ---------------------------------------------------------------- helpers

    protected function part(Request $request, Assessment $assessment, string $part, callable $present): JsonResponse
    {
        if (!$this->owns($request, $assessment)) {
            return $this->forbidden();
        }
        $run = $this->service->currentRun($assessment);
        if (!$run || !$run->isCompleted()) {
            return response()->json(['status' => 'success', 'data' => [], 'run' => $run ? $this->service->present($run, false) : null]);
        }
        $data = Cache::remember(
            StudentPerformanceService::cacheKey($assessment->id, $part) . ':' . $run->id . ':' . $run->updated_at?->timestamp,
            (int) config('performance.cache_ttl', 600),
            fn () => $present($run)
        );

        return response()->json(['status' => 'success', 'data' => $data, 'run' => $this->service->present($run, false)]);
    }

    protected function meta(Assessment $assessment): array
    {
        return [
            'finalized_answer_count' => $this->service->finalizedAnswersQuery($assessment)->count(),
            'expected_performance_percent' => $this->service->expected(),
            'minimum_responses' => $this->service->minResponses(),
        ];
    }

    /** STEP 34: aggregate performance needs view_analysis; per-student data needs view_student_data. */
    protected function owns(Request $request, Assessment $assessment, string $ability = 'view_analysis'): bool
    {
        $assessment->loadMissing('course');

        return app(\App\Services\CourseAccessService::class)->can($request->user(), $assessment->course, $ability);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Unauthorized access to this assessment.'], 403);
    }
}
