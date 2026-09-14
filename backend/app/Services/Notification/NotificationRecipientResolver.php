<?php

namespace App\Services\Notification;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\User;
use App\Services\CourseAccessService;
use Illuminate\Support\Collection;

/**
 * STEP 47: decides WHO may receive a notification. Recipients are always derived from the authorization model
 * (course ownership + active collaborator roles from config/collaboration.php) — never from request input.
 */
class NotificationRecipientResolver
{
    public function __construct(protected CourseAccessService $access) {}

    /**
     * Owner plus active collaborators whose role holds `$ability` on the course.
     *
     * @return Collection<int, User>
     */
    public function forCourse(?Course $course, string $ability, ?int $excludeUserId = null): Collection
    {
        if (!$course) {
            return collect();
        }
        $roles = $this->access->rolesWithAbility($ability);
        $ids = collect();
        if ($course->user_id && in_array(CourseCollaborator::ROLE_OWNER, $roles, true)) {
            $ids->push((int) $course->user_id);
        }
        if ($roles !== []) {
            $ids = $ids->merge(
                CourseCollaborator::query()
                    ->where('course_id', $course->id)
                    ->where('status', CourseCollaborator::STATUS_ACTIVE)
                    ->whereIn('role', $roles)
                    ->pluck('user_id')
                    ->map(fn ($id) => (int) $id)
            );
        }
        $ids = $ids->unique()->reject(fn (int $id) => $excludeUserId !== null && $id === $excludeUserId)->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function forAssessment(?Assessment $assessment, string $ability, ?int $excludeUserId = null): Collection
    {
        if (!$assessment) {
            return collect();
        }
        $assessment->loadMissing('course');

        return $this->forCourse($assessment->course, $ability, $excludeUserId);
    }

    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()->whereRaw('UPPER(role) = ?', ['ADMIN'])->get();
    }
}
