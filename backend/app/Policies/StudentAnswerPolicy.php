<?php

namespace App\Policies;

use App\Models\StudentAnswer;
use App\Models\User;

/**
 * Authorization chain: User -> Course -> Assessment -> StudentSubmission -> StudentAnswer.
 */
class StudentAnswerPolicy
{
    public function view(User $user, StudentAnswer $answer): bool
    {
        return $this->owns($user, $answer);
    }

    public function update(User $user, StudentAnswer $answer): bool
    {
        return $this->owns($user, $answer);
    }

    public function delete(User $user, StudentAnswer $answer): bool
    {
        return $this->owns($user, $answer);
    }

    protected function owns(User $user, StudentAnswer $answer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $ownerId = $answer->submission?->assessment?->course?->user_id;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
