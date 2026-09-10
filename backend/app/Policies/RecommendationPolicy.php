<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\User;

class RecommendationPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, $recommendation->analysisReport?->assessment?->course, 'view_analysis');
    }

    public function update(User $user, Recommendation $recommendation): bool
    {
        return $this->allows($user, $recommendation->analysisReport?->assessment?->course, 'approve_recommendation');
    }
}
