<?php

namespace App\Listeners\Notifications;

use App\Events\PerformanceAnalysisCompleted;
use App\Events\PerformanceAnalysisFailed;
use App\Notifications\NotificationType;

/**
 * STEP 47 × STEP 30: student performance notifications carry aggregate identifiers only — no student names,
 * identifiers, ranks or marks. A learning gap is "a performance gap that may require faculty review".
 */
class PerformanceNotificationListener extends NotificationListener
{
    public function handleCompleted(PerformanceAnalysisCompleted $event): void
    {
        $this->guard('performance-completed', function () use ($event) {
            $run = $event->run->loadMissing('assessment.course');
            $assessment = $run->assessment;
            if (!$assessment) {
                return;
            }
            $recipients = $this->recipients->forAssessment($assessment, 'view_student_data');
            $title = $this->quote($assessment->title, 'the assessment');
            $url = "/assessments/{$assessment->id}/analysis";
            $data = ['assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'run_id' => $run->id, 'action_label' => 'Open Performance'];

            $this->notifications->notifyMany($recipients, NotificationType::PERFORMANCE_ANALYSIS_COMPLETED, [
                'title' => 'Performance analysis completed',
                'message' => "Student performance analysis for {$title} is ready to review.",
                'action_url' => $url, 'entity_type' => 'performance_analysis_run', 'entity_id' => $run->id, 'data' => $data,
            ]);

            $gaps = (array) (($run->summary ?? [])['gap_areas'] ?? []);
            if (count($gaps) > 0) {
                $this->notifications->notifyMany($recipients, NotificationType::LEARNING_GAP_DETECTED, [
                    'title' => 'Learning outcome review recommended',
                    'message' => 'FacultyLens identified ' . count($gaps) . ' learning-outcome performance gap' . (count($gaps) === 1 ? '' : 's') . " in {$title} that may require faculty review.",
                    'action_url' => $url, 'entity_type' => 'performance_analysis_run', 'entity_id' => $run->id,
                    'data' => $data + ['gap_count' => count($gaps), 'action_label' => 'Review Gaps'],
                ]);
            }
        });
    }

    public function handleFailed(PerformanceAnalysisFailed $event): void
    {
        $this->guard('performance-failed', function () use ($event) {
            $run = $event->run->loadMissing('assessment.course');
            $assessment = $run->assessment;
            if (!$assessment) {
                return;
            }
            $this->notifications->notifyMany($this->recipients->forAssessment($assessment, 'view_student_data'), NotificationType::PERFORMANCE_ANALYSIS_FAILED, [
                'title' => 'Performance analysis failed',
                'message' => 'The student performance analysis for ' . $this->quote($assessment->title, 'the assessment') . ' could not be completed. No grades were changed.',
                'action_url' => "/assessments/{$assessment->id}/analysis", 'entity_type' => 'performance_analysis_run', 'entity_id' => $run->id,
                'dedupe_key' => NotificationType::PERFORMANCE_ANALYSIS_FAILED . ":performance_analysis_run:{$run->id}:" . now()->format('YmdHi'),
                'data' => ['assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'run_id' => $run->id, 'action_label' => 'Retry'],
            ]);
        });
    }
}
