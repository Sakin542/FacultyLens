<?php

namespace App\Listeners\Notifications;

use App\Events\AssessmentAnalysisCompleted;
use App\Events\AssessmentAnalysisFailed;
use App\Events\QuestionGenerationCompleted;
use App\Events\QuestionGenerationFailed;
use App\Events\RecommendationsCreated;
use App\Events\RubricGenerated;
use App\Events\RubricGenerationFailed;
use App\Models\AnalysisReport;
use App\Notifications\NotificationType;

/**
 * STEP 47: AI workflow notifications. Wording never implies an academic decision was made:
 * analyses, recommendations, rubric drafts and generated questions are always "ready for faculty review".
 */
class AiNotificationListener extends NotificationListener
{
    public function handleAnalysisCompleted(AssessmentAnalysisCompleted $event): void
    {
        $this->guard('analysis-completed', function () use ($event) {
            $report = $event->report->loadMissing('assessment.course');
            $assessment = $report->assessment;
            if (!$assessment) {
                return;
            }
            $this->notifications->notifyMany(
                $this->recipients->forAssessment($assessment, 'view_analysis'),
                NotificationType::AI_ANALYSIS_COMPLETED,
                [
                    'title' => 'Assessment analysis completed',
                    'message' => 'AI analysis for ' . $this->quote($assessment->title, 'the assessment') . ' is ready for review.',
                    'action_url' => "/assessments/{$assessment->id}/analysis",
                    'entity_type' => 'analysis_report',
                    'entity_id' => $report->id,
                    'data' => ['assessment_id' => $assessment->id, 'analysis_id' => $report->id, 'course_id' => $assessment->course_id, 'analysis_version' => $report->analysis_version, 'action_label' => 'Open Analysis'],
                ],
            );
        });
    }

    public function handleAnalysisFailed(AssessmentAnalysisFailed $event): void
    {
        $this->guard('analysis-failed', function () use ($event) {
            $report = $event->report->loadMissing('assessment.course');
            $assessment = $report->assessment;
            if (!$assessment) {
                return;
            }
            $this->notifications->notifyMany(
                $this->recipients->forAssessment($assessment, 'run_analysis'),
                NotificationType::AI_ANALYSIS_FAILED,
                [
                    'title' => 'AI analysis failed',
                    'message' => 'The assessment analysis for ' . $this->quote($assessment->title, 'the assessment') . ' could not be completed. No academic data was changed.',
                    'action_url' => "/assessments/{$assessment->id}/analysis",
                    'entity_type' => 'analysis_report',
                    'entity_id' => $report->id,
                    // one failure notification per failed attempt (retries within the same minute collapse), not per queue retry
                    'dedupe_key' => NotificationType::AI_ANALYSIS_FAILED . ":analysis_report:{$report->id}:" . now()->format('YmdHi'),
                    'data' => ['assessment_id' => $assessment->id, 'analysis_id' => $report->id, 'course_id' => $assessment->course_id, 'action_label' => 'Retry Analysis'],
                ],
            );
        });
    }

    public function handleRecommendationsCreated(RecommendationsCreated $event): void
    {
        $this->guard('recommendations-created', function () use ($event) {
            if ($event->count <= 0) {
                return;
            }
            /** @var AnalysisReport $report */
            $report = $event->report->loadMissing('assessment.course');
            $assessment = $report->assessment;
            if (!$assessment) {
                return;
            }
            $this->notifications->notifyMany(
                $this->recipients->forAssessment($assessment, 'approve_recommendation'),
                NotificationType::AI_RECOMMENDATION_CREATED,
                [
                    'title' => 'New assessment recommendations',
                    'message' => 'FacultyLens generated ' . $event->count . ' new assessment recommendation' . ($event->count === 1 ? '' : 's') . ' for ' . $this->quote($assessment->title, 'the assessment') . '. Assessment analysis identified areas that may require review.',
                    'action_url' => "/assessments/{$assessment->id}/analysis",
                    'entity_type' => 'analysis_report',
                    'entity_id' => $report->id,
                    'data' => ['assessment_id' => $assessment->id, 'analysis_id' => $report->id, 'course_id' => $assessment->course_id, 'recommendation_count' => $event->count, 'action_label' => 'Review Recommendations'],
                ],
            );
        });
    }

    public function handleRubricGenerated(RubricGenerated $event): void
    {
        $this->guard('rubric-generated', function () use ($event) {
            $rubric = $event->rubric->loadMissing('question.assessment');
            $assessmentId = $rubric->question?->assessment_id ?? $rubric->assessment_id;
            $this->notifications->notify($event->actor, NotificationType::RUBRIC_GENERATED, [
                'title' => 'Rubric draft generated',
                'message' => 'A draft rubric (v' . $rubric->version . ') for question ' . ($rubric->question?->question_number ?? $rubric->question_id) . ' is ready for faculty review. It remains a draft until you approve it.',
                'action_url' => $assessmentId ? "/assessments/{$assessmentId}" : null,
                'entity_type' => 'rubric',
                'entity_id' => $rubric->id,
                'data' => ['rubric_id' => $rubric->id, 'question_id' => $rubric->question_id, 'assessment_id' => $assessmentId, 'version' => $rubric->version, 'status' => $rubric->status, 'action_label' => 'Review Rubric'],
            ], ['actor_id' => $event->actor->id]);
        });
    }

    public function handleRubricGenerationFailed(RubricGenerationFailed $event): void
    {
        $this->guard('rubric-failed', function () use ($event) {
            $question = $event->question;
            $this->notifications->notify($event->actor, NotificationType::RUBRIC_GENERATION_FAILED, [
                'title' => 'Rubric generation failed',
                'message' => 'The rubric for question ' . ($question->question_number ?? $question->id) . ' could not be generated. Please review the question and try again.',
                'action_url' => $question->assessment_id ? "/assessments/{$question->assessment_id}" : null,
                'entity_type' => 'question',
                'entity_id' => $question->id,
                'dedupe_key' => NotificationType::RUBRIC_GENERATION_FAILED . ":question:{$question->id}:" . now()->format('YmdHi'),
                'data' => ['question_id' => $question->id, 'assessment_id' => $question->assessment_id, 'action_label' => 'Retry'],
            ], ['actor_id' => $event->actor->id]);
        });
    }

    public function handleQuestionGenerationCompleted(QuestionGenerationCompleted $event): void
    {
        $this->guard('qgen-completed', function () use ($event) {
            $request = $event->request->loadMissing('user');
            if (!$request->user) {
                return;
            }
            $count = (int) $request->generatedQuestions()->count();
            $this->notifications->notify($request->user, NotificationType::QUESTION_GENERATION_COMPLETED, [
                'title' => 'Question generation completed',
                'message' => 'Your ' . $count . ' generated question draft' . ($count === 1 ? '' : 's') . ' ' . ($count === 1 ? 'is' : 'are') . ' ready for faculty review. Drafts are not added to any assessment until you approve them.',
                'action_url' => "/courses/{$request->course_id}/question-generator?request={$request->id}",
                'entity_type' => 'question_generation_request',
                'entity_id' => $request->id,
                'data' => ['request_id' => $request->id, 'course_id' => $request->course_id, 'assessment_id' => $request->assessment_id, 'draft_count' => $count, 'action_label' => 'Review Drafts'],
            ]);
        });
    }

    public function handleQuestionGenerationFailed(QuestionGenerationFailed $event): void
    {
        $this->guard('qgen-failed', function () use ($event) {
            $request = $event->request->loadMissing('user');
            if (!$request->user) {
                return;
            }
            $this->notifications->notify($request->user, NotificationType::QUESTION_GENERATION_FAILED, [
                'title' => 'Question generation failed',
                'message' => 'The question generator could not produce drafts for your request. No questions were added. You can adjust the request and try again.',
                'action_url' => "/courses/{$request->course_id}/question-generator?request={$request->id}",
                'entity_type' => 'question_generation_request',
                'entity_id' => $request->id,
                'data' => ['request_id' => $request->id, 'course_id' => $request->course_id, 'action_label' => 'Open Request'],
            ]);
        });
    }
}
