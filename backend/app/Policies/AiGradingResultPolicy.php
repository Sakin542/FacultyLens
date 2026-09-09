<?php

namespace App\Policies;

use App\Models\AiGradingResult;
use App\Models\User;

/**
 * Authorization chain: User -> Course (user_id) -> Assessment -> StudentSubmission -> StudentAnswer -> AiGradingResult.
 */
class AiGradingResultPolicy
{
    public function view(User $user, AiGradingResult $result): bool
    {
        return $this->owns($user, $result);
    }

    public function update(User $user, AiGradingResult $result): bool
    {
        return $this->owns($user, $result);
    }

    protected function owns(User $user, AiGradingResult $result): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $ownerId = $result->submission?->assessment?->course?->user_id
            ?? $result->studentAnswer?->submission?->assessment?->course?->user_id;

        return $ownerId !== null && $ownerId === $user->id;
    }
}
