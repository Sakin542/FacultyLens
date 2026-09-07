<?php

namespace App\Policies;

use App\Models\LearningOutcome;
use App\Models\User;

class LearningOutcomePolicy
{
    public function view(User $user, LearningOutcome $lo): bool
    {
        return $user->isAdmin() || $lo->course->user_id === $user->id;
    }

    public function update(User $user, LearningOutcome $lo): bool
    {
        return $user->isAdmin() || $lo->course->user_id === $user->id;
    }

    public function delete(User $user, LearningOutcome $lo): bool
    {
        return $user->isAdmin() || $lo->course->user_id === $user->id;
    }
}

