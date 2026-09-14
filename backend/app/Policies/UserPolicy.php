<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\User;

/**
 * Account-level abilities. Profile-picture mutation is always self-only (the controller derives the
 * target from the session); viewing another faculty member's avatar requires a shared course.
 */
class UserPolicy
{
    public function updateProfilePicture(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }

    public function deleteProfilePicture(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }

    /**
     * Self, administrators, or two users who work on at least one common course
     * (owner ↔ active collaborator, or both active collaborators of the same course).
     */
    public function viewProfilePicture(User $user, User $target): bool
    {
        if ($user->id === $target->id || $user->isAdmin()) {
            return true;
        }

        return $this->sharesCourse($user, $target);
    }

    protected function sharesCourse(User $a, User $b): bool
    {
        $active = CourseCollaborator::STATUS_ACTIVE;

        $courseIdsFor = function (User $u) use ($active) {
            return Course::query()
                ->where('user_id', $u->id)
                ->select('id')
                ->union(
                    CourseCollaborator::query()->where('user_id', $u->id)->where('status', $active)->select('course_id as id')
                );
        };

        return Course::query()
            ->whereIn('id', $courseIdsFor($a))
            ->whereIn('id', $courseIdsFor($b))
            ->exists();
    }
}
