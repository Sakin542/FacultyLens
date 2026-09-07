<?php

namespace App\Services;

use App\Models\AiImprovementSignal;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\RecommendationFeedback;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RecommendationFeedbackService
{
    /**
     * Authorize that the authenticated user owns the assessment this recommendation belongs to.
     */
    public function authorizeRecommendationOwner(Recommendation $recommendation, User $user): void
    {
        $recommendation->loadMissing('analysisReport.assessment.course');
        $course = $recommendation->analysisReport?->assessment?->course;

        if (!$course || $course->user_id !== $user->id) {
            throw new Exception('Unauthorized. You do not own the assessment for this recommendation.');
        }
    }

    /**
     * Submit faculty decision and optional feedback on an AI recommendation.
     * All operations execute inside an atomic database transaction.
     */
    public function submitFeedback(Recommendation $recommendation, User $user, array $data): array
    {
        $this->authorizeRecommendationOwner($recommendation, $user);

        $decisionUpper = strtoupper(trim($data['decision']));
        if (!in_array($decisionUpper, ['ACCEPTED', 'DISMISSED', 'REVIEWED'])) {
            throw new Exception("Invalid decision '{$data['decision']}'. Must be ACCEPTED, DISMISSED, or REVIEWED.");
        }

        $usefulnessRating = isset($data['usefulness_rating']) && $data['usefulness_rating'] !== null
            ? (int) $data['usefulness_rating']
            : null;

        if ($usefulnessRating !== null && ($usefulnessRating < 1 || $usefulnessRating > 5)) {
            throw new Exception("Usefulness rating must be between 1 and 5.");
        }

        $reason = !empty($data['reason']) ? trim($data['reason']) : null;
        $comment = !empty($data['comment']) ? trim($data['comment']) : null;
        $facultyNotes = !empty($data['faculty_notes']) ? trim($data['faculty_notes']) : $comment;

        return DB::transaction(function () use (
            $recommendation,
            $user,
            $decisionUpper,
            $usefulnessRating,
            $reason,
            $comment,
            $facultyNotes
        ) {
            $previousStatus = strtolower($recommendation->status ?? 'pending');
            $newStatus = strtolower($decisionUpper);

            // 1. Update recommendation status and notes (without modifying assessment or analysis scores)
            $recommendation->status = $newStatus;
            if ($facultyNotes !== null) {
                $recommendation->faculty_notes = $facultyNotes;
            }
            $recommendation->save();

            // 2. Record status transition audit record
            $decisionRecord = RecommendationDecision::create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'previous_status' => $previousStatus,
                'new_status' => $decisionUpper,
                'reason' => $reason,
            ]);

            // 3. Save faculty feedback record
            $feedbackRecord = RecommendationFeedback::create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decisionUpper,
                'usefulness_rating' => $usefulnessRating,
                'reason' => $reason,
                'comment' => $comment,
            ]);

            // 4. Generate structured AI improvement signal
            $signal = $this->generateImprovementSignal(
                $recommendation,
                $user,
                $decisionUpper,
                $usefulnessRating,
                $reason,
                $comment
            );

            return [
                'recommendation' => $recommendation->fresh(['feedback', 'decisions']),
                'decision' => $decisionRecord,
                'feedback' => $feedbackRecord,
                'signal' => $signal,
            ];
        });
    }

    /**
     * Direct status update (Accept, Dismiss, Review) with decision history and signal logging.
     */
    public function updateRecommendationStatus(
        Recommendation $recommendation,
        User $user,
        string $status,
        ?string $notes = null,
        ?string $reason = null
    ): array {
        return $this->submitFeedback($recommendation, $user, [
            'decision' => $status,
            'faculty_notes' => $notes,
            'reason' => $reason,
        ]);
    }

    /**
     * Derive and persist a structured AI Improvement Signal from faculty feedback.
     */
    protected function generateImprovementSignal(
        Recommendation $recommendation,
        User $user,
        string $decision,
        ?int $rating,
        ?string $reason,
        ?string $comment
    ): AiImprovementSignal {
        $report = $recommendation->analysisReport;
        $assessment = $report?->assessment;

        // Determine Signal Type
        if ($rating !== null && $rating >= 4 && $decision === 'ACCEPTED') {
            $signalType = 'RECOMMENDATION_USEFUL';
            $signalValue = 'positive';
        } elseif ($reason === 'NOT_APPLICABLE') {
            $signalType = 'RECOMMENDATION_NOT_APPLICABLE';
            $signalValue = 'neutral';
        } elseif ($reason === 'ALREADY_ADDRESSED') {
            $signalType = 'RECOMMENDATION_ALREADY_ADDRESSED';
            $signalValue = 'neutral';
        } elseif ($reason === 'INCORRECT_CONTEXT' || ($rating !== null && $rating <= 2)) {
            $signalType = 'RECOMMENDATION_NEEDS_REVIEW';
            $signalValue = 'negative';
        } elseif ($reason === 'NEEDS_MODIFICATION') {
            $signalType = 'RECOMMENDATION_NEEDS_MODIFICATION';
            $signalValue = 'neutral';
        } elseif ($decision === 'ACCEPTED') {
            $signalType = 'RECOMMENDATION_ACCEPTED';
            $signalValue = 'positive';
        } elseif ($decision === 'DISMISSED') {
            $signalType = 'RECOMMENDATION_DISMISSED';
            $signalValue = 'neutral';
        } elseif ($decision === 'REVIEWED') {
            $signalType = 'RECOMMENDATION_REVIEWED';
            $signalValue = 'neutral';
        } else {
            $signalType = 'RECOMMENDATION_FEEDBACK_RECORDED';
            $signalValue = 'neutral';
        }

        $metadata = [
            'category' => $recommendation->category,
            'priority' => $recommendation->priority,
            'source_metric' => $recommendation->source_metric,
            'decision' => $decision,
            'usefulness_rating' => $rating,
            'reason' => $reason,
            'problem_title' => $recommendation->problem ?? $recommendation->title,
            'comment_preview' => $comment ? mb_substr($comment, 0, 150) : null,
        ];

        return AiImprovementSignal::create([
            'user_id' => $user->id,
            'recommendation_id' => $recommendation->id,
            'analysis_report_id' => $report?->id ?? 0,
            'assessment_id' => $assessment?->id ?? 0,
            'signal_type' => $signalType,
            'signal_value' => $signalValue,
            'source' => 'faculty_feedback',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Retrieve paginated feedback records for the authenticated faculty member with filtering.
     */
    public function getFeedbackHistory(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = RecommendationFeedback::where('user_id', $user->id)
            ->with([
                'recommendation.analysisReport.assessment.course',
            ])
            ->orderByDesc('created_at');

        // Course filter
        if (!empty($filters['course_id']) && $filters['course_id'] !== 'all') {
            $courseId = (int) $filters['course_id'];
            $query->whereHas('recommendation.analysisReport.assessment', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        // Assessment filter
        if (!empty($filters['assessment_id']) && $filters['assessment_id'] !== 'all') {
            $assessmentId = (int) $filters['assessment_id'];
            $query->whereHas('recommendation.analysisReport', function ($q) use ($assessmentId) {
                $q->where('assessment_id', $assessmentId);
            });
        }

        // Decision filter (ACCEPTED, DISMISSED, REVIEWED)
        if (!empty($filters['decision']) && $filters['decision'] !== 'all') {
            $query->where('decision', strtoupper($filters['decision']));
        }

        // Rating filter (1 to 5)
        if (!empty($filters['rating']) && $filters['rating'] !== 'all') {
            $query->where('usefulness_rating', (int) $filters['rating']);
        }

        // Reason filter
        if (!empty($filters['reason']) && $filters['reason'] !== 'all') {
            $query->where('reason', $filters['reason']);
        }

        // Search in comments or recommendation titles
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', $search)
                    ->orWhereHas('recommendation', function ($rq) use ($search) {
                        $rq->where('problem', 'like', $search)
                            ->orWhere('title', 'like', $search)
                            ->orWhere('recommendation', 'like', $search);
                    });
            });
        }

        $paginator = $query->paginate($perPage);

        // Transform results into uniform resources
        $paginator->getCollection()->transform(function (RecommendationFeedback $item) {
            $rec = $item->recommendation;
            $assessment = $rec?->analysisReport?->assessment;
            $course = $assessment?->course;

            return [
                'id' => $item->id,
                'recommendation_id' => $item->recommendation_id,
                'decision' => $item->decision,
                'usefulness_rating' => $item->usefulness_rating,
                'reason' => $item->reason,
                'comment' => $item->comment,
                'created_at' => $item->created_at->toIso8601String(),
                'recommendation' => [
                    'id' => $rec?->id,
                    'category' => $rec?->category,
                    'priority' => $rec?->priority,
                    'problem' => $rec?->problem ?? $rec?->title,
                    'recommendation' => $rec?->recommendation ?? $rec?->description,
                    'status' => $rec?->status,
                ],
                'assessment' => [
                    'id' => $assessment?->id,
                    'title' => $assessment?->title,
                    'type' => $assessment?->type,
                ],
                'course' => [
                    'id' => $course?->id,
                    'code' => $course?->course_code,
                    'name' => $course?->course_name,
                ],
            ];
        });

        return $paginator;
    }

    /**
     * Get all feedback entries submitted for a specific recommendation.
     */
    public function getRecommendationFeedback(Recommendation $recommendation, User $user): array
    {
        $this->authorizeRecommendationOwner($recommendation, $user);

        return $recommendation->feedback()
            ->where('user_id', $user->id)
            ->get()
            ->toArray();
    }

    /**
     * Compute aggregated faculty feedback summary metrics.
     */
    public function getFeedbackSummary(User $user, ?int $courseId = null): array
    {
        $query = RecommendationFeedback::where('user_id', $user->id);

        if ($courseId) {
            $query->whereHas('recommendation.analysisReport.assessment', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            });
        }

        $allFeedback = $query->get();

        $total = $allFeedback->count();
        $accepted = $allFeedback->where('decision', 'ACCEPTED')->count();
        $dismissed = $allFeedback->where('decision', 'DISMISSED')->count();
        $reviewed = $allFeedback->where('decision', 'REVIEWED')->count();

        $ratings = $allFeedback->pluck('usefulness_rating')->filter(fn($r) => $r !== null);
        $avgUsefulness = $ratings->count() > 0 ? round($ratings->avg(), 1) : null;

        // Reason breakdown
        $reasonCounts = $allFeedback->groupBy('reason')->map->count()->toArray();
        unset($reasonCounts['']); // remove empty keys

        return [
            'total' => $total,
            'accepted' => $accepted,
            'dismissed' => $dismissed,
            'reviewed' => $reviewed,
            'average_usefulness' => $avgUsefulness,
            'reason_breakdown' => $reasonCounts,
        ];
    }

    /**
     * Retrieve paginated AI Improvement Signals generated from faculty feedback.
     */
    public function getImprovementSignals(User $user, array $filters = [], int $perPage = 15): array
    {
        $query = AiImprovementSignal::where('user_id', $user->id)
            ->with(['recommendation', 'assessment'])
            ->orderByDesc('created_at');

        if (!empty($filters['signal_type']) && $filters['signal_type'] !== 'all') {
            $query->where('signal_type', $filters['signal_type']);
        }

        if (!empty($filters['signal_value']) && $filters['signal_value'] !== 'all') {
            $query->where('signal_value', $filters['signal_value']);
        }

        if (!empty($filters['assessment_id']) && $filters['assessment_id'] !== 'all') {
            $query->where('assessment_id', (int) $filters['assessment_id']);
        }

        // Summary counts across all signals of the user
        $allSignals = AiImprovementSignal::where('user_id', $user->id)->get();
        $positiveCount = $allSignals->where('signal_value', 'positive')->count();
        $negativeCount = $allSignals->where('signal_value', 'negative')->count();
        $neutralCount = $allSignals->where('signal_value', 'neutral')->count();

        $paginator = $query->paginate($perPage);

        return [
            'summary' => [
                'total_signals' => $allSignals->count(),
                'positive_signals' => $positiveCount,
                'needs_review_signals' => $negativeCount,
                'context_neutral_signals' => $neutralCount,
                'disclaimer' => 'Faculty feedback is securely collected as structured improvement signals for future FacultyLens refinement. No automatic model retraining is triggered.',
            ],
            'signals' => $paginator,
        ];
    }
}

