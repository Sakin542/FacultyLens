<?php

namespace App\Policies;

use App\Models\PreviousQuestion;
use App\Models\User;

class PreviousQuestionPolicy
{
    public function view(User $user, PreviousQuestion $pq): bool
    {
        return $user->isAdmin() || $pq->course->user_id === $user->id;
    }

    public function update(User $user, PreviousQuestion $pq): bool
    {
        return $user->isAdmin() || $pq->course->user_id === $user->id;
    }

    public function delete(User $user, PreviousQuestion $pq): bool
    {
        return $user->isAdmin() || $pq->course->user_id === $user->id;
    }
}

