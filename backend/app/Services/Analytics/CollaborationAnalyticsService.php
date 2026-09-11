<?php

namespace App\Services\Analytics;

use App\Models\AuditLog;
use App\Models\CollaborationComment;
use App\Models\CourseCollaborationInvitation;
use App\Models\CourseCollaborator;
use App\Models\GeneratedQuestion;
use Illuminate\Support\Carbon;

/**
 * STEP 36: aggregate collaboration analytics (STEP 34) restricted to courses in scope — no comment bodies are exposed.
 */
class CollaborationAnalyticsService
{
    public function summary(array $courseIds): array
    {
        if ($courseIds === []) {
            return ['shared_courses' => 0, 'active_collaborators' => 0, 'pending_invitations' => 0, 'open_discussions' => 0, 'resolved_discussions' => 0, 'open_reviews' => 0, 'by_role' => []];
        }
        $active = CourseCollaborator::whereIn('course_id', $courseIds)->where('status', CourseCollaborator::STATUS_ACTIVE);
        $byRole = (clone $active)->selectRaw('role, COUNT(*) AS c')->groupBy('role')->pluck('c', 'role')->all();
        $comments = CollaborationComment::whereIn('course_id', $courseIds)->whereNull('parent_id')->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->all();

        return [
            'shared_courses' => (clone $active)->distinct('course_id')->count('course_id'),
            'active_collaborators' => (clone $active)->distinct('user_id')->count('user_id'),
            'pending_invitations' => CourseCollaborationInvitation::whereIn('course_id', $courseIds)->where('status', CourseCollaborationInvitation::STATUS_PENDING)->count(),
            'open_discussions' => (int) ($comments[CollaborationComment::STATUS_ACTIVE] ?? 0),
            'resolved_discussions' => (int) ($comments[CollaborationComment::STATUS_RESOLVED] ?? 0),
            'open_reviews' => GeneratedQuestion::whereHas('request', fn ($q) => $q->whereIn('course_id', $courseIds))->where('review_status', 'DRAFT')->count(),
            'by_role' => $byRole,
        ];
    }

    /** Daily activity counts from the existing audit log grouped into the configured action families. */
    public function activity(array $courseIds, int $days = 30): array
    {
        $families = (array) config('analytics.activity_actions');
        $since = Carbon::now()->subDays($days)->startOfDay();
        $series = [];
        if ($courseIds !== []) {
            $rows = AuditLog::whereIn('course_id', $courseIds)->where('created_at', '>=', $since)
                ->whereIn('action', array_merge(...array_values($families)))
                ->selectRaw('DATE(created_at) AS d, action, COUNT(*) AS c')->groupBy('d', 'action')->get();
            foreach ($rows as $r) {
                $series[$r->d] ??= array_fill_keys(array_keys($families), 0) + ['date' => $r->d];
                foreach ($families as $family => $actions) {
                    if (in_array($r->action, $actions, true)) {
                        $series[$r->d][$family] += (int) $r->c;
                    }
                }
            }
        }
        ksort($series);
        $totals = array_fill_keys(array_keys($families), 0);
        foreach ($series as $day) {
            foreach (array_keys($families) as $f) {
                $totals[$f] += $day[$f];
            }
        }

        return ['days' => $days, 'since' => $since->toDateString(), 'series' => array_values($series), 'totals' => $totals];
    }
}
