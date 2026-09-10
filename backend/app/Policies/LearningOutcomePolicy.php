<?php

namespace App\Policies;

use App\Models\LearningOutcome;
use App\Models\User;

class LearningOutcomePolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, LearningOutcome $lo): bool
    {
        return $this->allows($user, $lo->course, 'view');
    }

    public function update(User $user, LearningOutcome $lo): bool
    {
        return $this->allows($user, $lo->course, 'edit_course');
    }

    public function delete(User $user, LearningOutcome $lo): bool
    {
        return $this->allows($user, $lo->course, 'edit_course');
    }
}
