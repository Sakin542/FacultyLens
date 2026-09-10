<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Services\CourseAccessService;

/**
 * Shared helper: every resource policy resolves its course and asks CourseAccessService (STEP 34).
 */
trait ResolvesCourseAccess
{
    protected function access(): CourseAccessService
    {
        return app(CourseAccessService::class);
    }

    protected function allows(User $user, ?Course $course, string $ability): bool
    {
        return $course !== null && $this->access()->can($user, $course, $ability);
    }
}
