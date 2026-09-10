<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    use ResolvesCourseAccess;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'edit_course');
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'delete_course');
    }

    public function manageCollaborators(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'manage_collaborators');
    }

    public function comment(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'comment');
    }

    public function viewStudentData(User $user, Course $course): bool
    {
        return $this->allows($user, $course, 'view_student_data');
    }
}
