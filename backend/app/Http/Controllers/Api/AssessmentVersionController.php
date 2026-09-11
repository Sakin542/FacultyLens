<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAssessmentVersionRequest;
use App\Http\Requests\UpdateAssessmentVersionRequest;
use App\Models\Assessment;
use App\Models\AssessmentVersion;
use App\Services\AssessmentVersionComparisonService;
use App\Services\AssessmentVersionService;
use App\Services\AssessmentVersionValidationService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 38: Assessment Versioning API. Members may view/compare; OWNER/EDITOR (edit_assessment) may change state.
 * Versions with student submissions, and any APPROVED/FINALIZED/ARCHIVED version, are immutable.
 */
class AssessmentVersionController extends Controller
{
    public function __construct(
        protected AssessmentVersionService $service,
        protected AssessmentVersionComparisonService $comparison,
        protected AssessmentVersionValidationService $validation,
        protected CourseAccessService $access,
        protected AuditLogService $audit,
    ) {}

    /** GET /api/assessments/{assessment}/versions */
    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->allowed($request, $assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment.', 403);
        }
        $versions = $this->service->history($assessment);
        $current = $this->service->currentVersion($assessment);
        $working = $this->service->workingVersion($assessment);

        return $this->ok('Assessment versions retrieved.', [
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title, 'type' => $assessment->type, 'status' => $assessment->status, 'course_id' => $assessment->course_id, 'total_marks' => (float) $assessment->total_marks, 'question_count' => $assessment->questions()->count()],
            'versions' => $versions->map(fn ($v) => $this->service->summary($v))->values()->all(),
            'current_version_id' => $current?->id, 'working_version_id' => $working?->id, 'total_versions' => $versions->count(),
            'permissions' => $this->permissions($request, $assessment),
        ]);
    }

    /** POST /api/assessments/{assessment}/versions */
    public function store(CreateAssessmentVersionRequest $request, Assessment $assessment): JsonResponse
    {
        try {
            $version = $this->service->createVersion($request->user(), $assessment, $request->validated());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok("Version {$version->version_label} created as a draft.", $this->payload($request, $version), 201);
    }

    /** GET /api/assessments/{assessment}/versions/{version} */
    public function show(Request $request, Assessment $assessment, AssessmentVersion $version): JsonResponse
    {
        if ((int) $version->assessment_id !== (int) $assessment->id) {
            return $this->error('This version does not belong to the assessment.', 404);
        }
        if (!$this->allowed($request, $assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment version.', 403);
        }

        return $this->ok('Assessment version retrieved.', $this->payload($request, $version));
    }

    /** PUT /api/assessment-versions/{version} */
    public function update(UpdateAssessmentVersionRequest $request, AssessmentVersion $version): JsonResponse
    {
        try {
            $version = $this->service->updateDraftVersion($request->user(), $version, $request->validated());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok('Draft version updated.', $this->payload($request, $version));
    }

    /** POST /api/assessment-versions/{version}/submit-review */
    public function submitReview(Request $request, AssessmentVersion $version): JsonResponse
    {
        return $this->transition($request, $version, 'submitForReview', 'Version submitted for review.');
    }

    /** POST /api/assessment-versions/{version}/approve */
    public function approve(Request $request, AssessmentVersion $version): JsonResponse
    {
        return $this->transition($request, $version, 'approveVersion', 'Version approved. Finalize it to lock the snapshot.');
    }

    /** POST /api/assessment-versions/{version}/finalize */
    public function finalize(Request $request, AssessmentVersion $version): JsonResponse
    {
        return $this->transition($request, $version, 'finalizeVersion', 'Version finalized. It is now an immutable historical record.');
    }

    /** POST /api/assessment-versions/{version}/archive */
    public function archive(Request $request, AssessmentVersion $version): JsonResponse
    {
        return $this->transition($request, $version, 'archiveVersion', 'Version archived. It remains available for historical reference.');
    }

    /** POST /api/assessment-versions/{version}/restore — never overwrites; creates a new draft based on this version. */
    public function restore(Request $request, AssessmentVersion $version): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to restore versions of this assessment.', 403);
        }
        $data = $request->validate(['change_summary' => ['nullable', 'string', 'max:2000'], 'version_type' => ['nullable', 'in:MAJOR,MINOR,major,minor']]);
        try {
            $new = $this->service->restoreVersionAsNew($request->user(), $version, $data['change_summary'] ?? null, strtoupper($data['version_type'] ?? 'MAJOR'));
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }

        return $this->ok("Restored {$version->version_label} as new draft version {$new->version_label}.", $this->payload($request, $new), 201);
    }

    /** GET /api/assessment-versions/{version}/compare/{otherVersion} */
    public function compare(Request $request, AssessmentVersion $version, AssessmentVersion $otherVersion): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment version.', 403);
        }
        if ((int) $otherVersion->assessment_id !== (int) $version->assessment_id) {
            return $this->error('Versions of different assessments cannot be compared.', 422);
        }
        $result = $this->comparison->compare($version, $otherVersion);
        $this->audit->log('ASSESSMENT_VERSION_COMPARED', $version, $version->id, ['assessment_id' => $version->assessment_id, 'course_id' => $version->assessment->course_id, 'assessment_version_id' => $version->id,
            'other_version_id' => $otherVersion->id, 'summary' => $result['summary']], $request->user());

        return $this->ok('Versions compared.', $result);
    }

    /** GET /api/assessment-versions/{version}/analysis */
    public function analysis(Request $request, AssessmentVersion $version): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'view_analysis')) {
            return $this->error('Unauthorized access to this assessment version.', 403);
        }

        return $this->ok('Version analysis retrieved.', $this->service->analysisFor($version));
    }

    /** GET /api/assessment-versions/{version}/blueprint */
    public function blueprint(Request $request, AssessmentVersion $version): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment version.', 403);
        }
        $version->loadMissing('blueprint');

        return $this->ok($version->blueprint ? 'Version blueprint snapshot retrieved.' : 'This version has no blueprint snapshot.', [
            'assessment_version_id' => $version->id, 'version_label' => $version->version_label,
            'blueprint' => $version->blueprint ? $this->service->presentBlueprint($version->blueprint) : null,
            'profile' => $this->validation->profile($version),
        ]);
    }

    /** POST /api/assessment-versions/{version}/validate */
    public function validateVersion(Request $request, AssessmentVersion $version): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'view')) {
            return $this->error('Unauthorized access to this assessment version.', 403);
        }

        return $this->ok('Version validated.', $this->validation->validateBeforeFinalization($version));
    }

    // ------------------------------------------------------------- helpers

    protected function transition(Request $request, AssessmentVersion $version, string $method, string $message): JsonResponse
    {
        if (!$this->allowed($request, $version->assessment, 'edit_assessment')) {
            return $this->error('You are not allowed to change the state of this assessment version.', 403);
        }
        try {
            $version = $this->service->{$method}($request->user(), $version);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode(), ['validation' => $version->fresh()->validation_result]);
        }

        return $this->ok($message, $this->payload($request, $version));
    }

    protected function payload(Request $request, AssessmentVersion $version): array
    {
        $version->loadMissing('assessment');

        return ['version' => $this->service->present($version), 'analysis' => $this->service->analysisFor($version), 'permissions' => $this->permissions($request, $version->assessment)];
    }

    protected function permissions(Request $request, Assessment $assessment): array
    {
        $edit = $this->allowed($request, $assessment, 'edit_assessment');

        return ['view' => $this->allowed($request, $assessment, 'view'), 'edit' => $edit, 'approve' => $edit, 'finalize' => $edit, 'archive' => $edit, 'restore' => $edit, 'view_analysis' => $this->allowed($request, $assessment, 'view_analysis')];
    }

    protected function allowed(Request $request, ?Assessment $assessment, string $ability): bool
    {
        return $assessment !== null && $this->access->can($request->user(), $assessment->course, $ability);
    }

    protected function ok(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => $message, 'data' => $data], $status);
    }

    protected function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message] + ($extra ? ['data' => $extra] : []), $status);
    }
}
