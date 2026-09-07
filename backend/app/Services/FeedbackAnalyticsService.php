<?php

namespace App\Services;

use App\Models\RecommendationFeedback;
use App\Models\User;

class FeedbackAnalyticsService
{
    /**
     * Compute comprehensive feedback analytics for a faculty member.
     */
    public function getAnalytics(User $user, ?int $courseId = null): array
    {
        $query = RecommendationFeedback::where('user_id', $user->id)
            ->with(['recommendation.analysisReport.assessment']);

        if ($courseId) {
            $query->whereHas('recommendation.analysisReport.assessment', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        $feedbacks = $query->get();
        $total = $feedbacks->count();

        if ($total === 0) {
            return [
                'total_feedbacks' => 0,
                'acceptance_rate' => 0.0,
                'dismissal_rate' => 0.0,
                'review_rate' => 0.0,
                'average_usefulness' => null,
                'category_breakdown' => [],
                'top_reasons' => [],
            ];
        }

        $accepted = $feedbacks->where('decision', 'ACCEPTED')->count();
        $dismissed = $feedbacks->where('decision', 'DISMISSED')->count();
        $reviewed = $feedbacks->where('decision', 'REVIEWED')->count();

        $ratings = $feedbacks->pluck('usefulness_rating')->filter(fn($r) => $r !== null);
        $avgUsefulness = $ratings->count() > 0 ? round($ratings->avg(), 1) : null;

        // Group by Recommendation Category
        $byCategory = $feedbacks->groupBy(function ($fb) {
            return $fb->recommendation?->category ?? 'other';
        })->map(function ($group, $category) {
            $catTotal = $group->count();
            $catAccepted = $group->where('decision', 'ACCEPTED')->count();
            $catDismissed = $group->where('decision', 'DISMISSED')->count();
            $catRatings = $group->pluck('usefulness_rating')->filter(fn($r) => $r !== null);

            return [
                'category' => $category,
                'total' => $catTotal,
                'accepted' => $catAccepted,
                'dismissed' => $catDismissed,
                'acceptance_rate' => $catTotal > 0 ? round(($catAccepted / $catTotal) * 100, 1) : 0.0,
                'average_usefulness' => $catRatings->count() > 0 ? round($catRatings->avg(), 1) : null,
            ];
        })->values()->toArray();

        // Top Reasons
        $topReasons = $feedbacks->filter(fn($f) => !empty($f->reason))
            ->groupBy('reason')
            ->map->count()
            ->sortDesc()
            ->toArray();

        return [
            'total_feedbacks' => $total,
            'acceptance_rate' => round(($accepted / $total) * 100, 1),
            'dismissal_rate' => round(($dismissed / $total) * 100, 1),
            'review_rate' => round(($reviewed / $total) * 100, 1),
            'average_usefulness' => $avgUsefulness,
            'category_breakdown' => $byCategory,
            'top_reasons' => $topReasons,
        ];
    }
}

