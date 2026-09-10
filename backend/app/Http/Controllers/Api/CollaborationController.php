<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseCollaborationInvitation;
use App\Models\CourseCollaborator;
use App\Models\User;
use App\Services\CollaborationService;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 34: course collaboration — membership, invitations, activity. Backend is authoritative; the
 * `permissions` block is a UX hint for the client.
 */
class CollaborationController extends Controller
{
    public function __construct(protected CollaborationService $service, protected CourseAccessService $access) {}

    /** GET /api/courses/{course}/collaboration */
    public function show(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return $this->forbidden('You do not have access to this course.');
        }

        return response()->json(['status' => 'success', 'message' => 'Collaboration loaded successfully.', 'data' => $this->service->overview($request->user(), $course)]);
    }

    /** GET /api/courses/{course}/collaborators */
    public function collaborators(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return $this->forbidden('You do not have access to this course.');
        }
        $overview = $this->service->overview($request->user(), $course);

        return response()->json(['status' => 'success', 'message' => 'Collaborators retrieved.', 'data' => ['owner' => $overview['owner'], 'collaborators' => $overview['collaborators'], 'pending_invitations' => $overview['pending_invitations']]]);
    }

    /** GET /api/courses/{course}/collaboration/members — mentionable users */
    public function members(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return $this->forbidden('You do not have access to this course.');
        }

        return response()->json(['status' => 'success', 'message' => 'Members retrieved.', 'data' => $this->service->mentionableUsers($course)]);
    }

    /** POST /api/courses/{course}/collaborators/invite */
    public function invite(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('manageCollaborators', $course)) {
            return $this->forbidden('You do not have permission to manage collaborators for this course.');
        }
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(CourseCollaborator::ASSIGNABLE_ROLES)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $this->service->invite($request->user(), $course, $validated['email'], $validated['role'], $validated['message'] ?? null);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        // The raw token is only returned when mail is not configured for delivery (local/testing); never logged.
        $payload = $this->service->invitationPayload($result['invitation']->load(['course:id,course_code,course_name', 'inviter:id,name']));
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $payload['accept_url'] = rtrim(config('collaboration.frontend_url'), '/') . '/collaboration/invitations/' . $result['token'];
        }

        return response()->json(['status' => 'success', 'message' => 'Invitation sent.', 'data' => $payload], 201);
    }

    /** DELETE /api/courses/{course}/collaboration/invitations/{invitation} */
    public function revokeInvitation(Request $request, Course $course, CourseCollaborationInvitation $invitation): JsonResponse
    {
        if (!$request->user()->can('manageCollaborators', $course) || $invitation->course_id !== $course->id) {
            return $this->forbidden('You do not have permission to manage collaborators for this course.');
        }
        $this->service->revokeInvitation($request->user(), $invitation);

        return response()->json(['status' => 'success', 'message' => 'Invitation revoked.', 'data' => null]);
    }

    /** PATCH /api/courses/{course}/collaborators/{user}/role */
    public function changeRole(Request $request, Course $course, User $user): JsonResponse
    {
        if (!$request->user()->can('manageCollaborators', $course)) {
            return $this->forbidden('You do not have permission to manage collaborators for this course.');
        }
        $validated = $request->validate(['role' => ['required', Rule::in(CourseCollaborator::ASSIGNABLE_ROLES)]]);
        try {
            $row = $this->service->changeRole($request->user(), $course, $user, $validated['role']);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Collaborator role updated.', 'data' => ['user_id' => $user->id, 'role' => $row->role, 'status' => $row->status]]);
    }

    /** DELETE /api/courses/{course}/collaborators/{user} */
    public function remove(Request $request, Course $course, User $user): JsonResponse
    {
        if (!$request->user()->can('manageCollaborators', $course)) {
            return $this->forbidden('You do not have permission to manage collaborators for this course.');
        }
        try {
            $this->service->remove($request->user(), $course, $user);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Collaborator removed.', 'data' => null]);
    }

    /** GET /api/courses/{course}/collaboration/activity?page=&per_page= */
    public function activity(Request $request, Course $course): JsonResponse
    {
        if (!$request->user()->can('view', $course)) {
            return $this->forbidden('You do not have access to this course.');
        }
        $perPage = min(max((int) $request->get('per_page', config('collaboration.activity_per_page', 20)), 1), 100);
        $canSeeStudentData = $this->access->can($request->user(), $course, 'view_student_data');

        $query = AuditLog::where('course_id', $course->id)->with('user:id,name')->orderByDesc('id');
        if (!$canSeeStudentData) {
            // Grading / student events stay hidden from reviewers and viewers.
            $query->where('action', 'not like', 'STUDENT_%')->where('action', 'not like', 'GRADE_%')->where('action', 'not like', 'AI_GRADING%')->where('action', 'not like', 'SUBMISSION_%');
        }
        $page = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Activity retrieved.',
            'data' => collect($page->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'summary' => $this->describe($log),
                'metadata' => $this->publicMetadata($log),
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /api/collaboration/summary — dashboard widget */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => 'Collaboration summary retrieved.', 'data' => $this->service->summary($request->user())]);
    }

    /** GET /api/collaboration/invitations — pending invitations for the current user */
    public function myInvitations(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'message' => 'Pending invitations retrieved.', 'data' => $this->service->pendingFor($request->user())]);
    }

    /** GET /api/collaboration/invitations/{token} — public minimal preview */
    public function preview(string $token): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'message' => 'Invitation retrieved.', 'data' => $this->service->preview($token)]);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /** POST /api/collaboration/invitations/{token}/accept */
    public function accept(Request $request, string $token): JsonResponse
    {
        try {
            $member = $this->service->accept($request->user(), $token);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Invitation accepted. You now have access to the course.', 'data' => [
            'course_id' => $member->course_id, 'role' => $member->role, 'status' => $member->status,
        ]]);
    }

    /** POST /api/collaboration/invitations/{token}/decline */
    public function decline(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->service->decline($request->user(), $token);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Invitation declined.', 'data' => ['status' => $invitation->status]]);
    }

    /** POST /api/collaboration/my-invitations/{invitation}/accept — signed-in addressee, no token needed */
    public function acceptById(Request $request, CourseCollaborationInvitation $invitation): JsonResponse
    {
        try {
            $member = $this->service->acceptById($request->user(), $invitation);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Invitation accepted. You now have access to the course.', 'data' => ['course_id' => $member->course_id, 'role' => $member->role, 'status' => $member->status]]);
    }

    /** POST /api/collaboration/my-invitations/{invitation}/decline */
    public function declineById(Request $request, CourseCollaborationInvitation $invitation): JsonResponse
    {
        try {
            $invitation = $this->service->declineById($request->user(), $invitation);
        } catch (HttpException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json(['status' => 'success', 'message' => 'Invitation declined.', 'data' => ['status' => $invitation->status]]);
    }

    // ------------------------------------------------------------------ helpers

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function describe(AuditLog $log): string
    {
        $actor = $log->user?->name ?? 'Someone';
        $m = $log->metadata ?? [];
        $entity = strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $log->entity_type ?? 'item'));

        return match ($log->action) {
            'COLLABORATOR_INVITED' => "{$actor} invited a collaborator as " . ucfirst(strtolower($m['role'] ?? 'member')) . '.',
            'COLLABORATION_ACCEPTED' => "{$actor} joined the course as " . ucfirst(strtolower($m['role'] ?? 'member')) . '.',
            'COLLABORATION_DECLINED' => "{$actor} declined a collaboration invitation.",
            'COLLABORATOR_ROLE_CHANGED' => "{$actor} changed a collaborator's role from " . ucfirst(strtolower($m['from'] ?? '?')) . ' to ' . ucfirst(strtolower($m['to'] ?? '?')) . '.',
            'COLLABORATOR_REMOVED' => "{$actor} removed a collaborator.",
            'COMMENT_CREATED' => "{$actor} commented on " . str_replace('_', ' ', $m['commentable_type'] ?? 'a resource') . (isset($m['commentable_id']) ? " #{$m['commentable_id']}" : '') . '.',
            'COMMENT_UPDATED' => "{$actor} edited a comment.",
            'COMMENT_RESOLVED' => "{$actor} resolved a discussion.",
            'COMMENT_REOPENED' => "{$actor} reopened a discussion.",
            'COMMENT_DELETED' => "{$actor} deleted a comment.",
            'QUESTION_APPROVED' => "{$actor} approved generated question #{$log->entity_id}.",
            'QUESTION_ADDED_TO_ASSESSMENT' => "{$actor} added an approved generated question to an assessment.",
            'RUBRIC_APPROVED' => "{$actor} approved rubric #{$log->entity_id}.",
            'ASSESSMENT_CREATED' => "{$actor} created assessment \"" . ($m['title'] ?? '') . '".',
            'ASSESSMENT_UPDATED' => "{$actor} updated assessment \"" . ($m['title'] ?? '') . '".',
            'ASSESSMENT_DELETED' => "{$actor} deleted assessment \"" . ($m['title'] ?? '') . '".',
            default => "{$actor} " . strtolower(str_replace('_', ' ', $log->action)) . " ({$entity} #{$log->entity_id}).",
        };
    }

    protected function publicMetadata(AuditLog $log): array
    {
        $m = $log->metadata ?? [];

        return array_intersect_key($m, array_flip(['role', 'from', 'to', 'title', 'commentable_type', 'commentable_id', 'parent_id', 'generation_method', 'grounded', 'scope_type']));
    }
}
