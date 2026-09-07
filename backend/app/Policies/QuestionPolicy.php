<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    public function view(User $user, Question $question): bool
    {
        return $user->isAdmin() || $question->assessment->course->user_id === $user->id;
    }

    public function update(User $user, Question $question): bool
    {
        return $user->isAdmin() || $question->assessment->course->user_id === $user->id;
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->isAdmin() || $question->assessment->course->user_id === $user->id;
    }
}

