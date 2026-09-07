<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    /**
     * Determine whether the user can view any assessments.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the assessment.
     */
    public function view(User $user, Assessment $assessment): bool
    {
        return $user->isAdmin() || $assessment->course->user_id === $user->id;
    }

    /**
     * Determine whether the user can create assessments.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the assessment.
     */
    public function update(User $user, Assessment $assessment): bool
    {
        return $user->isAdmin() || $assessment->course->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the assessment.
     */
    public function delete(User $user, Assessment $assessment): bool
    {
        return $user->isAdmin() || $assessment->course->user_id === $user->id;
    }

    /**
     * Determine whether the user can trigger AI analysis on the assessment.
     */
    public function analyze(User $user, Assessment $assessment): bool
    {
        return $user->isAdmin() || $assessment->course->user_id === $user->id;
    }
}

