<?php

namespace App\Listeners\Notifications;

use App\Events\AssessmentVersionStatusChanged;
use App\Notifications\NotificationType;

/**
 * STEP 47 × STEP 38: assessment version lifecycle notifications.
 * The notification only reports what faculty did; finalization/approval remain faculty workflow actions.
 *
 *  CREATED / RESTORED / ARCHIVED → other editors of the course (the actor already knows)
 *  APPROVED / FINALIZED          → every editor including the actor (milestones worth a record)
 *  SUBMITTED (for review)        → REVIEW_ASSIGNED to editors other than the submitter
 *  APPROVED                      → REVIEW_COMPLETED to the submitter/creator if someone else approved
 */
class AssessmentVersionNotificationListener extends NotificationListener
{
    public function handle(AssessmentVersionStatusChanged $event): void
    {
        $this->guard('assessment-version', function () use ($event) {
            $version = $event->version->loadMissing('assessment.course');
            $assessment = $version->assessment;
            if (!$assessment) {
                return;
            }
            $action = strtoupper($event->action);
            $label = $this->quote($version->version_label, 'v' . $version->version_number);
            $title = $this->quote($assessment->title, 'the assessment');
            $url = "/assessments/{$assessment->id}/versions/{$version->id}";
            $data = ['assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'version_id' => $version->id, 'version_label' => $version->version_label, 'status' => $version->status, 'actor_id' => $event->actor->id];
            $opts = ['actor_id' => $event->actor->id];
            $entity = ['entity_type' => 'assessment_version', 'entity_id' => $version->id];

            switch ($action) {
                case 'CREATED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment', $event->actor->id), NotificationType::ASSESSMENT_VERSION_CREATED, $entity + [
                        'title' => 'Assessment version created',
                        'message' => "{$event->actor->name} created version {$label} of {$title}.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                    ], $opts);
                    break;

                case 'RESTORED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment', $event->actor->id), NotificationType::ASSESSMENT_VERSION_RESTORED, $entity + [
                        'title' => 'Assessment version restored',
                        'message' => "{$event->actor->name} restored a previous structure of {$title} as new draft version {$label}.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                    ], $opts);
                    break;

                case 'SUBMITTED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment', $event->actor->id), NotificationType::REVIEW_ASSIGNED, $entity + [
                        'title' => 'Review assigned',
                        'message' => "You have been assigned to review version {$label} of {$title}.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Review'],
                        'dedupe_key' => NotificationType::REVIEW_ASSIGNED . ":assessment_version:{$version->id}:" . ($version->submitted_at?->timestamp ?? now()->timestamp),
                    ], $opts);
                    break;

                case 'APPROVED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment'), NotificationType::ASSESSMENT_VERSION_APPROVED, $entity + [
                        'title' => 'Assessment version approved',
                        'message' => "Version {$label} of {$title} was approved by {$event->actor->name}.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                    ], $opts);
                    $submitterId = (int) ($version->created_by ?? 0);
                    if ($submitterId > 0 && $submitterId !== (int) $event->actor->id) {
                        $this->notifications->notify($submitterId, NotificationType::REVIEW_COMPLETED, $entity + [
                            'title' => 'Review completed',
                            'message' => "The assigned review for version {$label} of {$title} has been completed.",
                            'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                        ], $opts);
                    }
                    break;

                case 'FINALIZED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment'), NotificationType::ASSESSMENT_VERSION_FINALIZED, $entity + [
                        'title' => 'Assessment version finalized',
                        'message' => "Assessment version {$label} of {$title} has been finalized by {$event->actor->name} and is now locked for editing.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                    ], $opts);
                    break;

                case 'ARCHIVED':
                    $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'edit_assessment', $event->actor->id), NotificationType::ASSESSMENT_VERSION_ARCHIVED, $entity + [
                        'title' => 'Assessment version archived',
                        'message' => "Version {$label} of {$title} was archived. Archived versions stay available read-only.",
                        'action_url' => $url, 'data' => $data + ['action_label' => 'Open Version'],
                    ], $opts);
                    break;
            }
        });
    }
}
