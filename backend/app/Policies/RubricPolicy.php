<?php

namespace App\Policies;

use App\Models\Rubric;
use App\Models\User;

/**
 * Authorization chain: User -> Course (user_id) -> Assessment -> Question -> Rubric.
 */
class RubricPolicy
{
    public function view(User $user, Rubric $rubric): bool
    {
        return $this->owns($user, $rubric);
    }

    public function update(User $user, Rubric $rubric): bool
    {
        return $this->owns($user, $rubric) && !$rubric->isArchived();
    }

    public function delete(User $user, Rubric $rubric): bool
    {
        return $this->owns($user, $rubric);
    }

    public function approve(User $user, Rubric $rubric): bool
    {
        return $this->owns($user, $rubric) && $rubric->isDraft();
    }

    protected function owns(User $user, Rubric $rubric): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $courseOwnerId = $rubric->question?->assessment?->course?->user_id
            ?? $rubric->assessment?->course?->user_id;

        return $courseOwnerId !== null && $courseOwnerId === $user->id;
    }
}
