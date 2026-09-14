<?php

namespace App\Listeners\Notifications;

use App\Events\GradingCompleted;
use App\Events\GradingFailed;
use App\Events\InterGraderReviewRequired;
use App\Notifications\NotificationType;

/**
 * STEP 47 × STEP 27–29: grading notifications. An AI grading suggestion is only ever "ready for faculty review";
 * inter-grader wording indicates review may be useful and never says who is right.
 */
class GradingNotificationListener extends NotificationListener
{
    public function handleGradingCompleted(GradingCompleted $event): void
    {
        $this->guard('grading-completed', function () use ($event) {
            $result = $event->result->loadMissing(['requester', 'question']);
            if (!$result->requester) {
                return;
            }
            $this->notifications->notify($result->requester, NotificationType::GRADING_COMPLETED, [
                'title' => 'AI grading suggestion ready',
                'message' => 'The AI grading suggestion for question ' . ($result->question?->question_number ?? $result->question_id) . ' is ready for your review. Marks are only applied when you confirm them.',
                'action_url' => "/submissions/{$result->student_submission_id}",
                'entity_type' => 'ai_grading_result',
                'entity_id' => $result->id,
                'data' => ['grading_result_id' => $result->id, 'submission_id' => $result->student_submission_id, 'question_id' => $result->question_id, 'action_label' => 'Review Suggestion'],
            ]);
        });
    }

    public function handleGradingFailed(GradingFailed $event): void
    {
        $this->guard('grading-failed', function () use ($event) {
            $result = $event->result->loadMissing(['requester', 'question']);
            if (!$result->requester) {
                return;
            }
            $this->notifications->notify($result->requester, NotificationType::GRADING_FAILED, [
                'title' => 'AI grading could not be completed',
                'message' => 'The AI grading suggestion for question ' . ($result->question?->question_number ?? $result->question_id) . ' could not be produced. No marks were changed; you can grade manually or retry.',
                'action_url' => "/submissions/{$result->student_submission_id}",
                'entity_type' => 'ai_grading_result',
                'entity_id' => $result->id,
                'data' => ['grading_result_id' => $result->id, 'submission_id' => $result->student_submission_id, 'question_id' => $result->question_id, 'action_label' => 'Open Submission'],
            ]);
        });
    }

    public function handleInterGraderReviewRequired(InterGraderReviewRequired $event): void
    {
        $this->guard('inter-grader', function () use ($event) {
            $assessment = $event->assessment->loadMissing('course');
            $this->notifications->notifyMany(
                $this->recipients->forAssessment($assessment, 'view_student_data'),
                NotificationType::INTER_GRADER_REVIEW_REQUIRED,
                [
                    'title' => 'Grading consistency review recommended',
                    'message' => 'FacultyLens detected variation between faculty grading results for ' . $this->quote($assessment->title, 'an assessment') . ' that may require review.',
                    'action_url' => "/assessments/{$assessment->id}/submissions",
                    'entity_type' => 'assessment',
                    'entity_id' => $assessment->id,
                    'dedupe_key' => NotificationType::INTER_GRADER_REVIEW_REQUIRED . ":assessment:{$assessment->id}:q" . ($event->questionId ?? 0) . ':' . now()->format('Ymd'),
                    'data' => ['assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'question_id' => $event->questionId, 'action_label' => 'Review Grading'],
                ],
            );
        });
    }
}
