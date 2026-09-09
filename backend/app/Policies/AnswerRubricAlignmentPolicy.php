<?php

namespace App\Policies;

use App\Models\AnswerRubricAlignment;
use App\Models\User;

/**
 * Authorization chain: User -> Course (user_id) -> Assessment -> StudentSubmission -> StudentAnswer -> Alignment.
 */
class AnswerRubricAlignmentPolicy
{
    public function view(User $user, AnswerRubricAlignment $alignment): bool
    {
        return $this->owns($user, $alignment);
    }

    public function update(User $user, AnswerRubricAlignment $alignment): bool
    {
        return $this->owns($user, $alignment);
    }

    protected function owns(User $user, AnswerRubricAlignment $alignment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $ownerId = $alignment->submission?->assessment?->course?->user_id
            ?? $alignment->studentAnswer?->submission?->assessment?->course?->user_id;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
