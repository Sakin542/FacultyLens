<?php

namespace App\Policies;

use App\Models\Recommendation;
use App\Models\User;

class RecommendationPolicy
{
    public function view(User $user, Recommendation $recommendation): bool
    {
        return $user->isAdmin() || $recommendation->analysisReport?->assessment?->course?->user_id === $user->id;
    }

    public function update(User $user, Recommendation $recommendation): bool
    {
        return $user->isAdmin() || $recommendation->analysisReport?->assessment?->course?->user_id === $user->id;
    }
}

