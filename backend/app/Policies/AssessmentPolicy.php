<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    use ResolvesCourseAccess;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'edit_assessment');
    }

    public function delete(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'delete_assessment');
    }

    public function analyze(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'run_analysis');
    }

    public function viewAnalysis(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'view_analysis');
    }

    public function viewStudentData(User $user, Assessment $assessment): bool
    {
        return $this->allows($user, $assessment->course, 'view_student_data');
    }
}
