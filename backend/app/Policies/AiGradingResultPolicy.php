<?php

namespace App\Policies;

use App\Models\AiGradingResult;
use App\Models\User;

class AiGradingResultPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, AiGradingResult $result): bool
    {
        $course = $result->submission?->assessment?->course ?? $result->studentAnswer?->submission?->assessment?->course;

        return $this->allows($user, $course, 'view_student_data');
    }

    public function update(User $user, AiGradingResult $result): bool
    {
        return $this->view($user, $result);
    }
}
