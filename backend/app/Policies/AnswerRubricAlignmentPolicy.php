<?php

namespace App\Policies;

use App\Models\AnswerRubricAlignment;
use App\Models\User;

class AnswerRubricAlignmentPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, AnswerRubricAlignment $alignment): bool
    {
        $course = $alignment->submission?->assessment?->course ?? $alignment->studentAnswer?->submission?->assessment?->course;

        return $this->allows($user, $course, 'view_student_data');
    }

    public function update(User $user, AnswerRubricAlignment $alignment): bool
    {
        return $this->view($user, $alignment);
    }
}
