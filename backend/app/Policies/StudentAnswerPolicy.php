<?php

namespace App\Policies;

use App\Models\StudentAnswer;
use App\Models\User;

/**
 * Student answers are private academic data: only roles with view_student_data (OWNER/EDITOR) may access them.
 */
class StudentAnswerPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, StudentAnswer $answer): bool
    {
        return $this->allows($user, $answer->submission?->assessment?->course, 'view_student_data');
    }

    public function update(User $user, StudentAnswer $answer): bool
    {
        return $this->view($user, $answer);
    }

    public function delete(User $user, StudentAnswer $answer): bool
    {
        return $this->view($user, $answer);
    }
}
