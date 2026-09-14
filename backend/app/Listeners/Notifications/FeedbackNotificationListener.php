<?php

namespace App\Listeners\Notifications;

use App\Events\FacultyFeedbackCreated;
use App\Notifications\NotificationType;

/**
 * STEP 47 × STEP 20: faculty feedback on an AI recommendation is shared with the other faculty who may decide on
 * that course (owner/editors) — the free-text notes are private and never copied into the notification.
 */
class FeedbackNotificationListener extends NotificationListener
{
    public function handle(FacultyFeedbackCreated $event): void
    {
        $this->guard('feedback', function () use ($event) {
            $rec = $event->recommendation->loadMissing('analysisReport.assessment.course');
            $assessment = $rec->analysisReport?->assessment;
            if (!$assessment) {
                return;
            }
            $decision = strtolower(str_replace('_', ' ', $event->decision));
            $this->notifications->notifyMany(
                $this->recipients->forAssessment($assessment, 'approve_recommendation', $event->submitter->id),
                NotificationType::FACULTY_FEEDBACK_RECEIVED,
                [
                    'title' => 'Faculty feedback received',
                    'message' => "{$event->submitter->name} recorded feedback ({$decision}) on an AI recommendation for " . $this->quote($assessment->title, 'an assessment') . '.',
                    'action_url' => "/assessments/{$assessment->id}/analysis",
                    'entity_type' => 'recommendation',
                    'entity_id' => $rec->id,
                    'dedupe_key' => NotificationType::FACULTY_FEEDBACK_RECEIVED . ":recommendation:{$rec->id}:{$event->submitter->id}:" . now()->format('YmdHi'),
                    'data' => ['recommendation_id' => $rec->id, 'assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'decision' => $event->decision, 'actor_name' => $event->submitter->name, 'action_label' => 'Open Recommendations'],
                ],
                ['actor_id' => $event->submitter->id],
            );
        });
    }
}
