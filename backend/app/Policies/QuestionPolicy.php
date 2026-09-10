<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, Question $question): bool
    {
        return $this->allows($user, $question->assessment?->course, 'view');
    }

    public function update(User $user, Question $question): bool
    {
        return $this->allows($user, $question->assessment?->course, 'edit_question');
    }

    public function delete(User $user, Question $question): bool
    {
        return $this->allows($user, $question->assessment?->course, 'edit_question');
    }
}
