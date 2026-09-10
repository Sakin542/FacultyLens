<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Rubric;
use App\Models\User;

class RubricPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, $this->course($rubric), 'view');
    }

    public function update(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, $this->course($rubric), 'edit_question') && !$rubric->isArchived();
    }

    public function delete(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, $this->course($rubric), 'edit_question');
    }

    public function approve(User $user, Rubric $rubric): bool
    {
        return $this->allows($user, $this->course($rubric), 'approve_rubric') && $rubric->isDraft();
    }

    protected function course(Rubric $rubric): ?Course
    {
        return $rubric->question?->assessment?->course ?? $rubric->assessment?->course;
    }
}
