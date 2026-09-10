<?php

namespace App\Policies;

use App\Models\PreviousQuestion;
use App\Models\User;

class PreviousQuestionPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, PreviousQuestion $pq): bool
    {
        return $this->allows($user, $pq->course, 'view');
    }

    public function update(User $user, PreviousQuestion $pq): bool
    {
        return $this->allows($user, $pq->course, 'edit_question');
    }

    public function delete(User $user, PreviousQuestion $pq): bool
    {
        return $this->allows($user, $pq->course, 'edit_question');
    }
}
