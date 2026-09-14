<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * STEP 34: single authority for "what may this user do on this course".
 *
 * Role resolution: courses.user_id (or admin) => OWNER; otherwise the ACTIVE course_collaborators row.
 * Abilities come from config('collaboration.matrix'). Policies and controllers call this service;
 * they never compare user ids themselves. Results are memoised per request only (no shared cache).
 */
class CourseAccessService
{
    /** @var array<string, string|null> */
    protected array $roleCache = [];

    public static function make(): self
    {
        return app(self::class);
    }

    public function roleFor(User $user, Course $course): ?string
    {
        $key = $user->id . ':' . $course->id;
        if (array_key_exists($key, $this->roleCache)) {
            return $this->roleCache[$key];
        }

        if ($user->isAdmin() || $course->user_id === $user->id) {
            return $this->roleCache[$key] = CourseCollaborator::ROLE_OWNER;
        }

        $role = CourseCollaborator::query()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->where('status', CourseCollaborator::STATUS_ACTIVE)
            ->value('role');

        return $this->roleCache[$key] = ($role ? strtoupper($role) : null);
    }

    public function isOwner(User $user, Course $course): bool
    {
        return $this->roleFor($user, $course) === CourseCollaborator::ROLE_OWNER;
    }

    public function isMember(User $user, Course $course): bool
    {
        return $this->roleFor($user, $course) !== null;
    }

    public function can(User $user, ?Course $course, string $ability): bool
    {
        if (!$course) {
            return false;
        }
        $role = $this->roleFor($user, $course);
        if ($role === null) {
            return false;
        }

        return in_array($role, $this->rolesFor($ability), true);
    }

    /**
     * @return array<string, bool>
     */
    public function permissions(User $user, Course $course): array
    {
        $out = [];
        foreach (array_keys((array) config('collaboration.matrix')) as $ability) {
            $out[$ability] = $this->can($user, $course, $ability);
        }

        return $out;
    }

    /**
     * Base query for courses the user may see (owned or active member).
     */
    public function accessibleCourses(User $user): Builder
    {
        return Course::query()->accessibleBy($user);
    }

    /**
     * @return int[]
     */
    public function accessibleCourseIds(User $user): array
    {
        return $this->accessibleCourses($user)->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Drop the memoised role after a membership change within the same request. */
    public function forget(User $user, Course $course): void
    {
        unset($this->roleCache[$user->id . ':' . $course->id]);
    }

    /**
     * @return string[]
     */
    protected function rolesFor(string $ability): array
    {
        $roles = (array) config("collaboration.matrix.{$ability}", []);
        if ($ability === 'comment' && config('collaboration.viewer_can_comment')) {
            $roles[] = CourseCollaborator::ROLE_VIEWER;
        }

        return $roles;
    }
}
