<?php

namespace App\Services;

use App\Models\CollaborationComment;
use App\Models\Course;
use App\Models\User;
use App\Notifications\CollaborationNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 34: threaded discussion on academic resources. Comments are plain text (rendered as text by the
 * client), never alter the resource, and stay in history when resolved. Mentions are restricted to members.
 */
class CollaborationCommentService
{
    public function __construct(
        protected CourseAccessService $access,
        protected CollaborationService $collaboration,
        protected AuditLogService $audit,
    ) {}

    /**
     * Resolve + authorize a commentable target: it must exist and belong to the given course.
     *
     * @return array{type: string, model: Model}
     */
    public function resolveTarget(Course $course, string $type, int $id): array
    {
        $type = strtolower(trim($type));
        $class = config("collaboration.commentables.{$type}");
        if (!$class) {
            throw new HttpException(422, 'Unsupported discussion target.');
        }
        /** @var Model|null $model */
        $model = $class::query()->find($id);
        if (!$model || $this->courseIdOf($model) !== (int) $course->id) {
            throw new HttpException(404, 'Discussion target not found in this course.');
        }

        return ['type' => $type, 'model' => $model];
    }

    public function list(User $user, Course $course, array $filters, int $perPage = 20)
    {
        $query = CollaborationComment::where('course_id', $course->id)->whereNull('parent_id')
            ->with(['user:id,name', 'resolver:id,name', 'replies.user:id,name'])
            ->orderByDesc('id');

        if (!empty($filters['commentable_type']) && !empty($filters['commentable_id'])) {
            $target = $this->resolveTarget($course, $filters['commentable_type'], (int) $filters['commentable_id']);
            $query->where('commentable_type', $target['type'])->where('commentable_id', $target['model']->getKey());
        }
        if (!empty($filters['status'])) {
            $query->where('status', strtoupper($filters['status']));
        }

        return $query->paginate(min(max($perPage, 1), 100));
    }

    public function create(User $user, Course $course, array $data): CollaborationComment
    {
        $target = $this->resolveTarget($course, $data['commentable_type'], (int) $data['commentable_id']);
        $parent = null;
        if (!empty($data['parent_id'])) {
            $parent = CollaborationComment::where('course_id', $course->id)->find($data['parent_id']);
            if (!$parent) {
                throw new HttpException(404, 'Parent comment not found.');
            }
            if ($parent->parent_id) {
                $parent = $parent->parent; // keep threads one level deep: replies attach to the root comment
            }
        }
        $mentions = $this->validMentions($course, $data['mentions'] ?? []);
        $body = trim((string) $data['body']);

        // Idempotency: identical body from the same user on the same target within 10s is a retry.
        $dupe = CollaborationComment::where('course_id', $course->id)->where('user_id', $user->id)
            ->where('commentable_type', $target['type'])->where('commentable_id', $target['model']->getKey())
            ->where('parent_id', $parent?->id)->where('body', $body)->where('created_at', '>=', now()->subSeconds(10))->first();
        if ($dupe) {
            return $dupe->load(['user:id,name', 'replies.user:id,name']);
        }

        $comment = DB::transaction(fn () => CollaborationComment::create([
            'course_id' => $course->id,
            'user_id' => $user->id,
            'commentable_type' => $target['type'],
            'commentable_id' => $target['model']->getKey(),
            'parent_id' => $parent?->id,
            'body' => $body,
            'mentions' => $mentions ?: null,
            'status' => CollaborationComment::STATUS_ACTIVE,
        ]));

        $this->audit->log('COMMENT_CREATED', $comment, $comment->id, [
            'course_id' => $course->id, 'commentable_type' => $target['type'], 'commentable_id' => $target['model']->getKey(),
            'parent_id' => $parent?->id, 'mentions' => $mentions,
        ], $user);

        $this->notifyParticipants($user, $course, $comment, $parent, $mentions, $target['type']);

        return $comment->load(['user:id,name', 'replies.user:id,name']);
    }

    public function update(User $user, CollaborationComment $comment, string $body): CollaborationComment
    {
        if ($comment->user_id !== $user->id) {
            throw new HttpException(403, 'You can only edit your own comments.');
        }
        if ($comment->status === CollaborationComment::STATUS_DELETED) {
            throw new HttpException(422, 'Deleted comments cannot be edited.');
        }
        $body = trim($body);
        if ($body === $comment->body) {
            return $comment;
        }
        $comment->update(['body' => $body, 'edited_at' => now()]);
        $this->audit->log('COMMENT_UPDATED', $comment, $comment->id, ['course_id' => $comment->course_id, 'previous_length' => mb_strlen($comment->getOriginal('body') ?? '')], $user);

        return $comment->fresh(['user:id,name', 'replies.user:id,name']);
    }

    /**
     * Soft delete: author or course owner. History remains attributable in the audit trail.
     */
    public function delete(User $user, CollaborationComment $comment): void
    {
        $course = $comment->course;
        if ($comment->user_id !== $user->id && !$this->access->isOwner($user, $course)) {
            throw new HttpException(403, 'You can only delete your own comments.');
        }
        DB::transaction(function () use ($comment) {
            $comment->update(['status' => CollaborationComment::STATUS_DELETED]);
            $comment->delete();
        });
        $this->audit->log('COMMENT_DELETED', $comment, $comment->id, ['course_id' => $comment->course_id, 'moderated' => $comment->user_id !== $user->id], $user);
    }

    public function resolve(User $user, CollaborationComment $comment): CollaborationComment
    {
        $this->assertCanResolve($user, $comment);
        $comment->update(['status' => CollaborationComment::STATUS_RESOLVED, 'resolved_by' => $user->id, 'resolved_at' => now()]);
        $this->audit->log('COMMENT_RESOLVED', $comment, $comment->id, ['course_id' => $comment->course_id], $user);

        return $comment->fresh(['user:id,name', 'resolver:id,name', 'replies.user:id,name']);
    }

    public function reopen(User $user, CollaborationComment $comment): CollaborationComment
    {
        $this->assertCanResolve($user, $comment);
        $comment->update(['status' => CollaborationComment::STATUS_ACTIVE, 'resolved_by' => null, 'resolved_at' => null]);
        $this->audit->log('COMMENT_REOPENED', $comment, $comment->id, ['course_id' => $comment->course_id], $user);

        return $comment->fresh(['user:id,name', 'resolver:id,name', 'replies.user:id,name']);
    }

    public function payload(CollaborationComment $c, bool $withReplies = true): array
    {
        return [
            'id' => $c->id,
            'course_id' => $c->course_id,
            'commentable_type' => $c->commentable_type,
            'commentable_id' => $c->commentable_id,
            'parent_id' => $c->parent_id,
            'body' => $c->status === CollaborationComment::STATUS_DELETED ? '[deleted]' : $c->body,
            'mentions' => $c->mentions ?? [],
            'status' => $c->status,
            'author' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
            'resolved_by' => $c->relationLoaded('resolver') && $c->resolver ? ['id' => $c->resolver->id, 'name' => $c->resolver->name] : null,
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'edited_at' => $c->edited_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'replies' => $withReplies && $c->relationLoaded('replies') ? $c->replies->map(fn ($r) => $this->payload($r, false))->values()->all() : [],
            'reply_count' => $c->relationLoaded('replies') ? $c->replies->count() : null,
        ];
    }

    // ------------------------------------------------------------------ helpers

    protected function assertCanResolve(User $user, CollaborationComment $comment): void
    {
        if ($comment->parent_id) {
            throw new HttpException(422, 'Only top-level discussions can be resolved.');
        }
        // Author, or anyone who may decide on the course (OWNER/EDITOR), may resolve.
        if ($comment->user_id !== $user->id && !$this->access->can($user, $comment->course, 'approve_recommendation')) {
            throw new HttpException(403, 'You do not have permission to resolve this discussion.');
        }
    }

    /**
     * @return int[] ids that are actual course members
     */
    protected function validMentions(Course $course, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        if ($ids === []) {
            return [];
        }
        $members = array_column($this->collaboration->mentionableUsers($course), 'id');

        return array_values(array_intersect($ids, $members));
    }

    protected function notifyParticipants(User $actor, Course $course, CollaborationComment $comment, ?CollaborationComment $parent, array $mentions, string $type): void
    {
        $recipients = collect($mentions);
        if ($parent) {
            $recipients->push($parent->user_id);
            $recipients = $recipients->merge($parent->replies()->pluck('user_id'));
        }
        $recipientIds = $recipients->unique()->reject(fn ($id) => (int) $id === $actor->id)->values();
        if ($recipientIds->isEmpty()) {
            return;
        }
        $label = Str::of($type)->replace('_', ' ')->toString();
        foreach (User::whereIn('id', $recipientIds)->get() as $user) {
            if (!$this->access->isMember($user, $course)) {
                continue;
            }
            try {
                $user->notify(new CollaborationNotification([
                    'event' => in_array($user->id, $mentions, true) ? 'MENTION_RECEIVED' : 'COLLABORATION_COMMENT_ADDED',
                    'title' => in_array($user->id, $mentions, true) ? "{$actor->name} mentioned you" : "{$actor->name} replied to a discussion",
                    'body' => Str::limit($comment->body, 160),
                    'course_id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name,
                    'actor_name' => $actor->name, 'comment_id' => $comment->id, 'target' => $label,
                    'url' => rtrim(config('collaboration.frontend_url'), '/') . "/courses/{$course->id}/collaboration?comment={$comment->id}",
                ]));
            } catch (\Throwable) {
                // notification failures never break the request
            }
        }
    }

    protected function courseIdOf(Model $model): ?int
    {
        if ($model instanceof Course) {
            return (int) $model->getKey();
        }
        if (isset($model->course_id)) {
            return (int) $model->course_id;
        }
        if ($model instanceof \App\Models\Question || $model instanceof \App\Models\Rubric || $model instanceof \App\Models\AnalysisReport) {
            return $model->assessment?->course_id ? (int) $model->assessment->course_id : null;
        }
        if ($model instanceof \App\Models\Recommendation) {
            return $model->analysisReport?->assessment?->course_id ? (int) $model->analysisReport->assessment->course_id : null;
        }
        if ($model instanceof \App\Models\GeneratedQuestion) {
            return $model->request?->course_id ? (int) $model->request->course_id : null;
        }

        return null;
    }
}
