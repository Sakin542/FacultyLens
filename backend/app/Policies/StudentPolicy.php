<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function view(User $user, Student $student): bool
    {
        return $user->isAdmin() || $student->created_by === $user->id;
    }

    public function update(User $user, Student $student): bool
    {
        return $user->isAdmin() || $student->created_by === $user->id;
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->isAdmin() || $student->created_by === $user->id;
    }
}
