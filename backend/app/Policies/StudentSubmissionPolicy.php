<?php

namespace App\Policies;

use App\Models\StudentSubmission;
use App\Models\User;

/**
 * Authorization chain: User -> Course (user_id) -> Assessment -> StudentSubmission.
 */
class StudentSubmissionPolicy
{
    public function view(User $user, StudentSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    public function update(User $user, StudentSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    public function delete(User $user, StudentSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    protected function owns(User $user, StudentSubmission $submission): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $ownerId = $submission->assessment?->course?->user_id;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
