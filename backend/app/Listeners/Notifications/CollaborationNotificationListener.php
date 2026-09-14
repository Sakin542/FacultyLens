<?php

namespace App\Listeners\Notifications;

use App\Events\CollaborationInvitationAccepted;
use App\Events\CollaborationInvitationCreated;
use App\Events\CollaborationInvitationDeclined;
use App\Events\CollaboratorRemoved;
use App\Events\CollaboratorRoleChanged;
use App\Events\CommentCreated;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\CourseAccessService;
use Illuminate\Support\Str;

/**
 * STEP 47 × STEP 34: collaboration notifications. Recipients are always the addressee, the inviter, or verified
 * course members — never an arbitrary id from the request. Invitation tokens are NOT stored in notifications;
 * the action URL points at the authenticated pending-invitations page, which re-checks the addressee.
 */
class CollaborationNotificationListener extends NotificationListener
{
    public function handleInvitationCreated(CollaborationInvitationCreated $event): void
    {
        $this->guard('collab-invited', function () use ($event) {
            if (!$event->invitee) {
                return; // no account yet → e-mail only (CollaborationService)
            }
            $invitation = $event->invitation->loadMissing('course');
            $course = $invitation->course;
            $this->notifications->notify($event->invitee, NotificationType::COLLABORATION_INVITATION, [
                'title' => 'Collaboration invitation',
                'message' => "{$event->inviter->name} invited you to collaborate on " . $this->courseLabel($course) . ' as ' . ucfirst(strtolower($invitation->role)) . '.',
                'action_url' => '/collaboration/invitations',
                'entity_type' => 'course_collaboration_invitation',
                'entity_id' => $invitation->id,
                'expires_at' => $invitation->expires_at,
                'data' => ['course_id' => $course?->id, 'course_code' => $course?->course_code, 'course_name' => $course?->course_name, 'role' => $invitation->role, 'actor_name' => $event->inviter->name, 'invitation_id' => $invitation->id, 'action_label' => 'Review invitation'],
            ], ['actor_id' => $event->inviter->id]);
        });
    }

    public function handleInvitationAccepted(CollaborationInvitationAccepted $event): void
    {
        $this->guard('collab-accepted', function () use ($event) {
            $invitation = $event->invitation->loadMissing(['course', 'inviter']);
            $course = $invitation->course;
            if (!$invitation->inviter || !$course) {
                return;
            }
            $this->notifications->notify($invitation->inviter, NotificationType::COLLABORATION_ACCEPTED, [
                'title' => 'Collaboration invitation accepted',
                'message' => "{$event->user->name} accepted your invitation and joined " . $this->courseLabel($course) . ' as ' . ucfirst(strtolower($event->member->role)) . '.',
                'action_url' => "/courses/{$course->id}/collaboration",
                'entity_type' => 'course_collaboration_invitation',
                'entity_id' => $invitation->id,
                'data' => ['course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name, 'role' => $event->member->role, 'actor_name' => $event->user->name, 'action_label' => 'Open collaboration'],
            ], ['actor_id' => $event->user->id]);
        });
    }

    public function handleInvitationDeclined(CollaborationInvitationDeclined $event): void
    {
        $this->guard('collab-declined', function () use ($event) {
            $invitation = $event->invitation->loadMissing(['course', 'inviter']);
            $course = $invitation->course;
            if (!$invitation->inviter) {
                return;
            }
            $this->notifications->notify($invitation->inviter, NotificationType::COLLABORATION_REJECTED, [
                'title' => 'Collaboration invitation declined',
                'message' => "{$event->user->name} declined your invitation to collaborate on " . $this->courseLabel($course) . '.',
                'action_url' => $course ? "/courses/{$course->id}/collaboration" : null,
                'entity_type' => 'course_collaboration_invitation',
                'entity_id' => $invitation->id,
                'data' => ['course_id' => $course?->id, 'course_code' => $course?->course_code, 'actor_name' => $event->user->name, 'action_label' => 'Open collaboration'],
            ], ['actor_id' => $event->user->id]);
        });
    }

    public function handleCollaboratorRemoved(CollaboratorRemoved $event): void
    {
        $this->guard('collab-removed', function () use ($event) {
            $this->notifications->notify($event->member, NotificationType::COLLABORATION_REMOVED, [
                'title' => 'Collaboration access removed',
                'message' => 'Your collaboration access to ' . $this->courseLabel($event->course) . ' has been removed.',
                'action_url' => '/collaboration/invitations',
                'entity_type' => 'course',
                'entity_id' => $event->course->id,
                'dedupe_key' => NotificationType::COLLABORATION_REMOVED . ":course:{$event->course->id}:" . now()->timestamp,
                'data' => ['course_id' => $event->course->id, 'course_code' => $event->course->course_code, 'course_name' => $event->course->course_name, 'role' => $event->role, 'actor_name' => $event->actor->name],
            ], ['actor_id' => $event->actor->id]);
        });
    }

    public function handleRoleChanged(CollaboratorRoleChanged $event): void
    {
        $this->guard('collab-role', function () use ($event) {
            $this->notifications->notify($event->member, NotificationType::COLLABORATION_ROLE_CHANGED, [
                'title' => 'Your collaboration role changed',
                'message' => "{$event->actor->name} changed your role on " . $this->courseLabel($event->course) . ' from ' . ucfirst(strtolower($event->from)) . ' to ' . ucfirst(strtolower($event->to)) . '.',
                'action_url' => "/courses/{$event->course->id}",
                'entity_type' => 'course',
                'entity_id' => $event->course->id,
                'dedupe_key' => NotificationType::COLLABORATION_ROLE_CHANGED . ":course:{$event->course->id}:{$event->to}:" . now()->timestamp,
                'data' => ['course_id' => $event->course->id, 'course_code' => $event->course->course_code, 'from' => $event->from, 'to' => $event->to, 'actor_name' => $event->actor->name, 'action_label' => 'Open course'],
            ], ['actor_id' => $event->actor->id]);
        });
    }

    /**
     * Mentioned members get MENTION_RECEIVED; other thread participants get COMMENT_CREATED.
     * Only current course members are eligible (re-checked here, not trusted from the payload).
     */
    public function handleCommentCreated(CommentCreated $event): void
    {
        $this->guard('collab-comment', function () use ($event) {
            $access = app(CourseAccessService::class);
            $ids = collect($event->mentions)->merge($event->participants)->map(fn ($id) => (int) $id)->unique()
                ->reject(fn (int $id) => $id === (int) $event->actor->id)->values();
            if ($ids->isEmpty()) {
                return;
            }
            $excerpt = Str::limit(trim((string) $event->comment->body), 140);
            $url = "/courses/{$event->course->id}/collaboration?comment={$event->comment->id}";
            $base = ['course_id' => $event->course->id, 'course_code' => $event->course->course_code, 'comment_id' => $event->comment->id, 'actor_name' => $event->actor->name, 'target_type' => $event->comment->commentable_type, 'target_id' => $event->comment->commentable_id, 'action_label' => 'Open discussion'];

            foreach (User::whereIn('id', $ids)->get() as $user) {
                if (!$access->isMember($user, $event->course)) {
                    continue;
                }
                $mentioned = in_array((int) $user->id, array_map('intval', $event->mentions), true);
                $this->notifications->notify($user, $mentioned ? NotificationType::MENTION_RECEIVED : NotificationType::COMMENT_CREATED, [
                    'title' => $mentioned ? "{$event->actor->name} mentioned you" : "{$event->actor->name} commented on a discussion",
                    'message' => ($mentioned ? 'You were mentioned in a collaboration comment on ' : 'A collaborator commented on ') . $this->courseLabel($event->course) . ($excerpt !== '' ? ": “{$excerpt}”" : '.'),
                    'action_url' => $url,
                    'entity_type' => 'collaboration_comment',
                    'entity_id' => $event->comment->id,
                    'data' => $base + ['mentioned' => $mentioned],
                ], ['actor_id' => $event->actor->id]);
            }
        });
    }

    protected function courseLabel($course): string
    {
        if (!$course) {
            return 'a course';
        }

        return trim(($course->course_code ? $course->course_code . ' — ' : '') . ($course->course_name ?? ''));
    }
}
