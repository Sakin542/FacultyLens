<?php

namespace App\Services;

use App\Http\Controllers\Api\CourseController;
use App\Models\Course;
use App\Models\CourseCollaborationInvitation;
use App\Models\CourseCollaborator;
use App\Models\User;
use App\Notifications\CollaborationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 34: membership + invitation workflow. Authorization lives in CoursePolicy/CourseAccessService;
 * this service owns the business rules (single-use hashed tokens, expiry, duplicate prevention, owner protection).
 * Transactions wrap persistence only; notifications are dispatched after commit.
 */
class CollaborationService
{
    public function __construct(
        protected CourseAccessService $access,
        protected AuditLogService $audit,
    ) {}

    // ------------------------------------------------------------------ overview

    public function overview(User $user, Course $course): array
    {
        $course->loadMissing('user:id,name,email');
        $members = $course->collaborators()
            ->whereIn('status', [CourseCollaborator::STATUS_ACTIVE, CourseCollaborator::STATUS_PENDING])
            ->with(['user:id,name,email,department', 'inviter:id,name'])
            ->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 ELSE 1 END")->orderBy('id')
            ->get();
        $pending = $course->collaborationInvitations()
            ->where('status', CourseCollaborationInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->with('inviter:id,name')->orderByDesc('id')->get();

        $canManage = $this->access->can($user, $course, 'manage_collaborators');

        return [
            'course' => ['id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name],
            'owner' => $course->user ? ['id' => $course->user->id, 'name' => $course->user->name, 'email' => $canManage ? $course->user->email : null] : null,
            'current_user' => ['id' => $user->id, 'role' => $this->access->roleFor($user, $course)],
            'permissions' => $this->access->permissions($user, $course),
            'collaborators' => $members->map(fn (CourseCollaborator $c) => $this->memberPayload($c, $canManage))->values()->all(),
            'pending_invitations' => $canManage ? $pending->map(fn ($i) => $this->invitationPayload($i))->values()->all() : [],
            'roles' => CourseCollaborator::ASSIGNABLE_ROLES,
            'role_matrix' => config('collaboration.matrix'),
        ];
    }

    // ------------------------------------------------------------------ invitations

    /**
     * @return array{invitation: CourseCollaborationInvitation, token: string}
     */
    public function invite(User $inviter, Course $course, string $email, string $role, ?string $message = null): array
    {
        $email = Str::lower(trim($email));
        $role = strtoupper($role);
        if (!in_array($role, CourseCollaborator::ASSIGNABLE_ROLES, true)) {
            throw new HttpException(422, 'Invalid collaboration role.');
        }
        if (Str::lower($course->user?->email ?? '') === $email || $inviter->email === $email) {
            throw new HttpException(422, 'The course owner is already a member of this course.');
        }

        $invitee = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($invitee && $course->collaborators()->where('user_id', $invitee->id)->where('status', CourseCollaborator::STATUS_ACTIVE)->exists()) {
            throw new HttpException(422, 'This faculty member is already an active collaborator on this course.');
        }
        if ($course->collaborationInvitations()->where('invited_email', $email)->where('status', CourseCollaborationInvitation::STATUS_PENDING)->where('expires_at', '>', now())->exists()) {
            throw new HttpException(422, 'An invitation for this email is already pending.');
        }
        $max = (int) config('collaboration.max_collaborators_per_course', 50);
        if ($course->collaborators()->whereIn('status', [CourseCollaborator::STATUS_ACTIVE, CourseCollaborator::STATUS_PENDING])->count() >= $max) {
            throw new HttpException(422, "This course has reached the maximum of {$max} collaborators.");
        }

        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($course, $inviter, $invitee, $email, $role, $message, $token) {
            $invitation = CourseCollaborationInvitation::create([
                'course_id' => $course->id,
                'invited_user_id' => $invitee?->id,
                'invited_email' => $email,
                'invited_by' => $inviter->id,
                'role' => $role,
                'token_hash' => CourseCollaborationInvitation::hashToken($token),
                'status' => CourseCollaborationInvitation::STATUS_PENDING,
                'message' => $message ? Str::limit(trim($message), 1000, '') : null,
                'expires_at' => now()->addDays((int) config('collaboration.invitation_expires_days', 7)),
            ]);

            if ($invitee) {
                // Keep a PENDING membership row so the roster shows who has been invited.
                CourseCollaborator::updateOrCreate(
                    ['course_id' => $course->id, 'user_id' => $invitee->id],
                    ['invited_by' => $inviter->id, 'role' => $role, 'status' => CourseCollaborator::STATUS_PENDING, 'invited_at' => now(), 'accepted_at' => null, 'revoked_at' => null]
                );
            }

            return $invitation;
        });

        $this->audit->log('COLLABORATOR_INVITED', $invitation, $invitation->id, [
            'course_id' => $course->id, 'role' => $role, 'invited_user_id' => $invitee?->id,
        ], $inviter);

        $this->notify($invitee, [
            'event' => 'COLLABORATION_INVITATION_RECEIVED',
            'title' => 'FacultyLens Collaboration Invitation',
            'body' => "{$inviter->name} invited you to collaborate on {$course->course_code} — {$course->course_name} as " . ucfirst(strtolower($role)) . '.',
            'course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'actor_name' => $inviter->name, 'role' => $role,
            'url' => rtrim(config('collaboration.frontend_url'), '/') . "/collaboration/invitations/{$token}",
            'action_text' => 'Review invitation',
            'expires_at' => $invitation->expires_at->toDayDateTimeString(),
        ], true, $email);

        return ['invitation' => $invitation, 'token' => $token];
    }

    /**
     * Public, minimal preview — no course content, no member list.
     */
    public function preview(string $token): array
    {
        $invitation = $this->findByToken($token);
        $invitation->loadMissing(['course:id,course_code,course_name', 'inviter:id,name']);

        return $this->invitationPayload($invitation, true);
    }

    public function accept(User $user, string $token): CourseCollaborator
    {
        return $this->acceptInvitation($user, $this->findByToken($token));
    }

    /** Addressee is authenticated, so the id is enough (the token only proves email possession). */
    public function acceptById(User $user, CourseCollaborationInvitation $invitation): CourseCollaborator
    {
        return $this->acceptInvitation($user, $invitation->load(['course', 'inviter:id,name']));
    }

    public function declineById(User $user, CourseCollaborationInvitation $invitation): CourseCollaborationInvitation
    {
        return $this->declineInvitation($user, $invitation->load(['course', 'inviter:id,name']));
    }

    protected function acceptInvitation(User $user, CourseCollaborationInvitation $invitation): CourseCollaborator
    {
        $this->assertUsable($invitation);
        $this->assertAddressedTo($invitation, $user);

        $course = $invitation->course;
        if ($course->user_id === $user->id) {
            throw new HttpException(422, 'You already own this course.');
        }

        $member = DB::transaction(function () use ($invitation, $user, $course) {
            $member = CourseCollaborator::updateOrCreate(
                ['course_id' => $course->id, 'user_id' => $user->id],
                ['invited_by' => $invitation->invited_by, 'role' => $invitation->role, 'status' => CourseCollaborator::STATUS_ACTIVE,
                    'invited_at' => $invitation->created_at, 'accepted_at' => now(), 'revoked_at' => null]
            );
            $invitation->update(['status' => CourseCollaborationInvitation::STATUS_ACCEPTED, 'accepted_at' => now(), 'invited_user_id' => $user->id]);

            return $member;
        });

        $this->access->forget($user, $course);
        CourseController::forgetCourseListCaches($course);
        $this->audit->log('COLLABORATION_ACCEPTED', $member, $member->id, ['course_id' => $course->id, 'role' => $member->role], $user);
        $this->notify($invitation->inviter, [
            'event' => 'COLLABORATION_INVITATION_ACCEPTED',
            'title' => 'Collaboration invitation accepted',
            'body' => "{$user->name} joined {$course->course_code} as " . ucfirst(strtolower($member->role)) . '.',
            'course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'actor_name' => $user->name, 'role' => $member->role,
            'url' => rtrim(config('collaboration.frontend_url'), '/') . "/courses/{$course->id}/collaboration",
        ]);

        return $member->load('user:id,name,email');
    }

    public function decline(User $user, string $token): CourseCollaborationInvitation
    {
        return $this->declineInvitation($user, $this->findByToken($token));
    }

    protected function declineInvitation(User $user, CourseCollaborationInvitation $invitation): CourseCollaborationInvitation
    {
        $this->assertUsable($invitation);
        $this->assertAddressedTo($invitation, $user);

        DB::transaction(function () use ($invitation, $user) {
            $invitation->update(['status' => CourseCollaborationInvitation::STATUS_DECLINED, 'declined_at' => now(), 'invited_user_id' => $user->id]);
            CourseCollaborator::where('course_id', $invitation->course_id)->where('user_id', $user->id)
                ->where('status', CourseCollaborator::STATUS_PENDING)
                ->update(['status' => CourseCollaborator::STATUS_DECLINED]);
        });

        $this->audit->log('COLLABORATION_DECLINED', $invitation, $invitation->id, ['course_id' => $invitation->course_id], $user);
        $this->notify($invitation->inviter, [
            'event' => 'COLLABORATION_INVITATION_DECLINED',
            'title' => 'Collaboration invitation declined',
            'body' => "{$user->name} declined the invitation to {$invitation->course?->course_code}.",
            'course_id' => $invitation->course_id, 'course_code' => $invitation->course?->course_code, 'course_name' => $invitation->course?->course_name,
            'actor_name' => $user->name,
        ]);

        return $invitation->fresh();
    }

    public function revokeInvitation(User $actor, CourseCollaborationInvitation $invitation): CourseCollaborationInvitation
    {
        DB::transaction(function () use ($invitation) {
            $invitation->update(['status' => CourseCollaborationInvitation::STATUS_REVOKED, 'revoked_at' => now()]);
            if ($invitation->invited_user_id) {
                CourseCollaborator::where('course_id', $invitation->course_id)->where('user_id', $invitation->invited_user_id)
                    ->where('status', CourseCollaborator::STATUS_PENDING)->update(['status' => CourseCollaborator::STATUS_REVOKED, 'revoked_at' => now()]);
            }
        });
        $this->audit->log('COLLABORATION_INVITATION_REVOKED', $invitation, $invitation->id, ['course_id' => $invitation->course_id], $actor);

        return $invitation->fresh();
    }

    /**
     * Invitations addressed to this user (by id or email) that are still pending.
     */
    public function pendingFor(User $user)
    {
        return CourseCollaborationInvitation::query()
            ->where('status', CourseCollaborationInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->where(fn ($q) => $q->where('invited_user_id', $user->id)->orWhereRaw('LOWER(invited_email) = ?', [Str::lower($user->email)]))
            ->with(['course:id,course_code,course_name', 'inviter:id,name'])
            ->orderByDesc('id')->get()
            ->map(fn ($i) => $this->invitationPayload($i, true))->values()->all();
    }

    // ------------------------------------------------------------------ membership

    public function changeRole(User $actor, Course $course, User $member, string $role): CourseCollaborator
    {
        $role = strtoupper($role);
        if (!in_array($role, CourseCollaborator::ASSIGNABLE_ROLES, true)) {
            throw new HttpException(422, 'Invalid collaboration role.');
        }
        if ($member->id === $course->user_id) {
            throw new HttpException(422, 'The owner\'s role cannot be changed here. Use ownership transfer instead.');
        }
        $row = $course->collaborators()->where('user_id', $member->id)->where('status', CourseCollaborator::STATUS_ACTIVE)->first();
        if (!$row) {
            throw new HttpException(404, 'This user is not an active collaborator on the course.');
        }
        $from = $row->role;
        if ($from === $role) {
            return $row;
        }

        DB::transaction(fn () => $row->update(['role' => $role]));
        $this->access->forget($member, $course);
        $this->audit->log('COLLABORATOR_ROLE_CHANGED', $row, $row->id, ['course_id' => $course->id, 'from' => $from, 'to' => $role, 'member_id' => $member->id], $actor);
        $this->notify($member, [
            'event' => 'COLLABORATOR_ROLE_CHANGED',
            'title' => 'Your collaboration role changed',
            'body' => "{$actor->name} changed your role on {$course->course_code} from " . ucfirst(strtolower($from)) . ' to ' . ucfirst(strtolower($role)) . '.',
            'course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'actor_name' => $actor->name, 'role' => $role,
            'url' => rtrim(config('collaboration.frontend_url'), '/') . "/courses/{$course->id}",
        ]);

        return $row->fresh(['user:id,name,email']);
    }

    public function remove(User $actor, Course $course, User $member): void
    {
        if ($member->id === $course->user_id) {
            throw new HttpException(422, 'The course owner cannot be removed. Transfer ownership first.');
        }
        $row = $course->collaborators()->where('user_id', $member->id)->whereIn('status', [CourseCollaborator::STATUS_ACTIVE, CourseCollaborator::STATUS_PENDING])->first();
        if (!$row) {
            throw new HttpException(404, 'This user is not a collaborator on the course.');
        }

        DB::transaction(function () use ($row, $course, $member) {
            $row->update(['status' => CourseCollaborator::STATUS_REVOKED, 'revoked_at' => now()]);
            $course->collaborationInvitations()->where('invited_user_id', $member->id)->where('status', CourseCollaborationInvitation::STATUS_PENDING)
                ->update(['status' => CourseCollaborationInvitation::STATUS_REVOKED, 'revoked_at' => now()]);
        });

        $this->access->forget($member, $course);
        CourseController::forgetCourseListCaches($course);
        $this->audit->log('COLLABORATOR_REMOVED', $row, $row->id, ['course_id' => $course->id, 'member_id' => $member->id, 'role' => $row->role], $actor);
        $this->notify($member, [
            'event' => 'COLLABORATOR_REMOVED',
            'title' => 'Removed from a course collaboration',
            'body' => "You no longer have access to {$course->course_code} — {$course->course_name}.",
            'course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
            'actor_name' => $actor->name,
        ]);
    }

    /**
     * Members who may be @mentioned: owner + active collaborators only.
     */
    public function mentionableUsers(Course $course): array
    {
        $course->loadMissing('user:id,name');
        $out = $course->user ? [['id' => $course->user->id, 'name' => $course->user->name, 'role' => CourseCollaborator::ROLE_OWNER]] : [];
        foreach ($course->activeCollaborators()->with('user:id,name')->get() as $c) {
            if ($c->user) {
                $out[] = ['id' => $c->user->id, 'name' => $c->user->name, 'role' => $c->role];
            }
        }

        return $out;
    }

    /**
     * Dashboard summary — only courses the user owns or is an active member of are counted.
     */
    public function summary(User $user): array
    {
        $shared = CourseCollaborator::where('user_id', $user->id)->where('status', CourseCollaborator::STATUS_ACTIVE)
            ->with('course:id,course_code,course_name')->get();
        $courseIds = $this->access->accessibleCourseIds($user);
        $unresolved = $courseIds ? \App\Models\CollaborationComment::whereIn('course_id', $courseIds)->whereNull('parent_id')
            ->where('status', \App\Models\CollaborationComment::STATUS_ACTIVE)->count() : 0;
        $pending = $this->pendingFor($user);

        return [
            'shared_courses_count' => $shared->count(),
            'shared_courses' => $shared->map(fn ($c) => ['id' => $c->course?->id, 'course_code' => $c->course?->course_code, 'course_name' => $c->course?->course_name, 'role' => $c->role])->values()->all(),
            'pending_invitations_count' => count($pending),
            'pending_invitations' => $pending,
            'unresolved_discussions_count' => $unresolved,
            'unread_notifications_count' => $user->unreadNotifications()->count(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    protected function findByToken(string $token): CourseCollaborationInvitation
    {
        if (strlen($token) < 32) {
            throw new HttpException(404, 'Invitation not found.');
        }
        $invitation = CourseCollaborationInvitation::with(['course', 'inviter:id,name'])->where('token_hash', CourseCollaborationInvitation::hashToken($token))->first();
        if (!$invitation) {
            throw new HttpException(404, 'Invitation not found.');
        }

        return $invitation;
    }

    protected function assertUsable(CourseCollaborationInvitation $invitation): void
    {
        if ($invitation->status !== CourseCollaborationInvitation::STATUS_PENDING) {
            throw new HttpException(410, 'This invitation is no longer valid (' . strtolower($invitation->status) . ').');
        }
        if ($invitation->isExpired()) {
            $invitation->update(['status' => CourseCollaborationInvitation::STATUS_EXPIRED]);
            throw new HttpException(410, 'This invitation has expired.');
        }
    }

    protected function assertAddressedTo(CourseCollaborationInvitation $invitation, User $user): void
    {
        $matchesId = $invitation->invited_user_id !== null && $invitation->invited_user_id === $user->id;
        $matchesEmail = Str::lower($invitation->invited_email) === Str::lower($user->email);
        if (!$matchesId && !$matchesEmail) {
            throw new HttpException(403, 'This invitation was sent to a different faculty account.');
        }
    }

    protected function notify(?User $recipient, array $data, bool $mail = false, ?string $fallbackEmail = null): void
    {
        try {
            if ($recipient) {
                $recipient->notify(new CollaborationNotification($data, $mail));
            } elseif ($mail && $fallbackEmail) {
                \Illuminate\Support\Facades\Notification::route('mail', $fallbackEmail)->notify(new CollaborationNotification($data, true));
            }
        } catch (\Throwable $e) {
            // Never fail the request because a mail/notification channel is unavailable.
            Log::warning('CollaborationService: notification failed: ' . $e->getMessage());
        }
    }

    protected function memberPayload(CourseCollaborator $c, bool $showEmail): array
    {
        return [
            'id' => $c->id,
            'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $showEmail ? $c->user->email : null, 'department' => $c->user->department ?? null] : null,
            'role' => $c->role,
            'status' => $c->status,
            'invited_by' => $c->inviter ? ['id' => $c->inviter->id, 'name' => $c->inviter->name] : null,
            'invited_at' => $c->invited_at?->toIso8601String(),
            'accepted_at' => $c->accepted_at?->toIso8601String(),
        ];
    }

    public function invitationPayload(CourseCollaborationInvitation $i, bool $forInvitee = false): array
    {
        return [
            'id' => $i->id,
            'course' => $i->course ? ['id' => $i->course->id, 'course_code' => $i->course->course_code, 'course_name' => $i->course->course_name] : null,
            'invited_email' => $forInvitee ? null : $i->invited_email,
            'invited_by' => $i->inviter ? ['id' => $i->inviter->id, 'name' => $i->inviter->name] : null,
            'role' => $i->role,
            'status' => $i->isExpired() && $i->status === CourseCollaborationInvitation::STATUS_PENDING ? CourseCollaborationInvitation::STATUS_EXPIRED : $i->status,
            'message' => $i->message,
            'expires_at' => $i->expires_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
