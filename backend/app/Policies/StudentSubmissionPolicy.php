<?php

namespace App\Policies;

use App\Models\StudentSubmission;
use App\Models\User;

/**
 * Authorization chain: User -> Course (owner or active collaborator with view_student_data) -> Assessment -> Submission.
 */
class StudentSubmissionPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, StudentSubmission $submission): bool
    {
        return $this->allows($user, $submission->assessment?->course, 'view_student_data');
    }

    public function update(User $user, StudentSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }

    public function delete(User $user, StudentSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }
}
