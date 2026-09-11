<?php

namespace App\Policies;

use App\Models\AssessmentVersion;
use App\Models\User;

/**
 * STEP 38: version access follows the STEP 34 course matrix — members may view/compare,
 * OWNER/EDITOR (edit_assessment) may create, edit drafts, review, approve, finalize, archive and restore.
 */
class AssessmentVersionPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, AssessmentVersion $version): bool
    {
        return $this->allows($user, $version->assessment?->course, 'view');
    }

    public function update(User $user, AssessmentVersion $version): bool
    {
        return $this->allows($user, $version->assessment?->course, 'edit_assessment');
    }

    public function submitReview(User $user, AssessmentVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function approve(User $user, AssessmentVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function finalize(User $user, AssessmentVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function archive(User $user, AssessmentVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function restore(User $user, AssessmentVersion $version): bool
    {
        return $this->update($user, $version);
    }
}
