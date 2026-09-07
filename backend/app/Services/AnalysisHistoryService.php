<?php

namespace App\Services;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AnalysisHistoryService
{
    protected AssessmentReportService $reportService;

    public function __construct(AssessmentReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Get paginated analysis history for a faculty user with multi-criteria filtering.
     */
    public function getHistory(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = AnalysisReport::query()
            ->whereHas('assessment.course', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->with([
                'assessment.course',
                'assessmentReports' => function ($q) {
                    $q->where('generation_status', 'completed')->latest();
                },
            ]);

        // Analysis status filter (default: completed only)
        $status = $filters['status'] ?? 'completed';
        if ($status !== 'all') {
            $query->where('analysis_status', $status);
        }

        // Course filter
        if (!empty($filters['course_id'])) {
            $query->whereHas('assessment', function ($q) use ($filters) {
                $q->where('course_id', $filters['course_id']);
            });
        }

        // Assessment type filter
        if (!empty($filters['assessment_type'])) {
            $query->whereHas('assessment', function ($q) use ($filters) {
                $q->where('type', $filters['assessment_type']);
            });
        }

        // Academic year filter
        if (!empty($filters['academic_year'])) {
            $query->whereHas('assessment.course', function ($q) use ($filters) {
                $q->where('academic_year', $filters['academic_year']);
            });
        }

        // Semester filter
        if (!empty($filters['semester'])) {
            $query->whereHas('assessment.course', function ($q) use ($filters) {
                $q->where('semester', $filters['semester']);
            });
        }

        // Text search across assessment title, course code, and course name
        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('assessment', function ($aq) use ($search) {
                    $aq->where('title', 'like', $search)
                        ->orWhereHas('course', function ($cq) use ($search) {
                            $cq->where('course_code', 'like', $search)
                                ->orWhere('course_name', 'like', $search);
                        });
                });
            });
        }

        // Sorting
        $sort = $filters['sort'] ?? 'newest';
        match ($sort) {
            'oldest' => $query->orderBy('analyzed_at', 'asc')->orderBy('id', 'asc'),
            'highest_score' => $query->orderByDesc('overall_score'),
            'lowest_score' => $query->orderBy('overall_score', 'asc'),
            default => $query->orderByDesc('analyzed_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage);

        // Transform results into uniform history resource objects
        $paginator->getCollection()->transform(function (AnalysisReport $report) {
            $assessment = $report->assessment;
            $course = $assessment?->course;
            $latestPdf = $report->assessmentReports->first();

            return [
                'id' => $report->id,
                'assessment_id' => $report->assessment_id,
                'analysis_version' => $report->analysis_version,
                'is_current' => (bool) $report->is_current,
                'overall_score' => $report->overall_score !== null ? (float) $report->overall_score : null,
                'rating' => $this->reportService->getRatingLabel((float) ($report->overall_score ?? 0.0)),
                'topic_coverage_score' => $report->topic_coverage_score !== null ? (float) $report->topic_coverage_score : null,
                'learning_outcome_alignment_score' => $report->learning_outcome_alignment_score !== null ? (float) $report->learning_outcome_alignment_score : null,
                'difficulty_balance_score' => $report->difficulty_balance_score !== null ? (float) $report->difficulty_balance_score : null,
                'cognitive_level_balance_score' => $report->cognitive_level_balance_score !== null ? (float) $report->cognitive_level_balance_score : null,
                'similarity_score' => $report->similarity_score !== null ? (float) $report->similarity_score : null,
                'total_questions' => $report->total_questions,
                'similar_questions_count' => $report->similar_questions_count,
                'analysis_status' => $report->analysis_status,
                'analyzed_at' => $report->analyzed_at?->toIso8601String() ?? $report->created_at->toIso8601String(),
                'assessment' => [
                    'id' => $assessment?->id,
                    'title' => $assessment?->title,
                    'type' => $assessment?->type,
                    'total_marks' => (float) ($assessment?->total_marks ?? 0),
                ],
                'course' => [
                    'id' => $course?->id,
                    'course_code' => $course?->course_code,
                    'course_name' => $course?->course_name,
                    'semester' => $course?->semester,
                    'academic_year' => $course?->academic_year,
                ],
                'has_report' => (bool) $latestPdf,
                'report_id' => $latestPdf?->id,
                'report_uuid' => $latestPdf?->report_uuid,
            ];
        });

        return $paginator;
    }

    /**
     * Get all historical versions for a specific assessment.
     */
    public function getAssessmentHistory(Assessment $assessment, User $user): array
    {
        $assessment->loadMissing('course');
        if ($assessment->course->user_id !== $user->id) {
            throw new Exception('Unauthorized. You do not own this assessment.');
        }

        $reports = AnalysisReport::where('assessment_id', $assessment->id)
            ->with(['assessmentReports' => fn($q) => $q->where('generation_status', 'completed')->latest()])
            ->orderByDesc('analysis_version')
            ->get();

        $currentVersion = $reports->firstWhere('is_current', true)?->analysis_version
            ?? $reports->first()?->analysis_version
            ?? 1;

        $historyList = $reports->map(function (AnalysisReport $r) {
            $latestPdf = $r->assessmentReports->first();
            return [
                'id' => $r->id,
                'version' => $r->analysis_version,
                'is_current' => (bool) $r->is_current,
                'overall_score' => $r->overall_score !== null ? (float) $r->overall_score : null,
                'rating' => $this->reportService->getRatingLabel((float) ($r->overall_score ?? 0.0)),
                'topic_coverage_score' => $r->topic_coverage_score !== null ? (float) $r->topic_coverage_score : null,
                'learning_outcome_alignment_score' => $r->learning_outcome_alignment_score !== null ? (float) $r->learning_outcome_alignment_score : null,
                'difficulty_balance_score' => $r->difficulty_balance_score !== null ? (float) $r->difficulty_balance_score : null,
                'cognitive_level_balance_score' => $r->cognitive_level_balance_score !== null ? (float) $r->cognitive_level_balance_score : null,
                'similarity_score' => $r->similarity_score !== null ? (float) $r->similarity_score : null,
                'total_questions' => $r->total_questions,
                'analysis_status' => $r->analysis_status,
                'analyzed_at' => $r->analyzed_at?->toIso8601String() ?? $r->created_at->toIso8601String(),
                'has_report' => (bool) $latestPdf,
                'report_id' => $latestPdf?->id,
                'report_uuid' => $latestPdf?->report_uuid,
            ];
        });

        return [
            'assessment_id' => $assessment->id,
            'assessment_title' => $assessment->title,
            'course_code' => $assessment->course->course_code,
            'current_analysis_version' => $currentVersion,
            'total_versions' => $reports->count(),
            'history' => $historyList,
        ];
    }

    /**
     * Get complete historical analysis snapshot for a specific AnalysisReport ID.
     */
    public function getAnalysisDetails(int $analysisReportId, User $user): array
    {
        $report = AnalysisReport::with([
            'assessment.course.user',
            'assessment.course.learningOutcomes',
            'assessment.questions',
            'recommendations',
            'similarityMatches.previousQuestion',
            'learningOutcomeAlignments.learningOutcome',
            'assessmentReports' => fn($q) => $q->where('generation_status', 'completed')->latest(),
        ])->findOrFail($analysisReportId);

        $assessment = $report->assessment;
        if (!$assessment || $assessment->course->user_id !== $user->id) {
            throw new Exception('Unauthorized. You do not own this analysis report.');
        }

        // Build data using existing report data structure
        $rawFindings = $report->findings;
        if (is_string($rawFindings)) {
            $rawFindings = json_decode($rawFindings, true) ?? [];
        }
        if (!is_array($rawFindings)) {
            $rawFindings = [];
        }

        $qualityAnalysis = $rawFindings['quality_analysis'] ?? $rawFindings['quality'] ?? [];
        $alignmentAnalysis = $rawFindings['alignment_analysis'] ?? $rawFindings['alignment'] ?? [];
        $similarityAnalysis = $rawFindings['similarity_analysis'] ?? $rawFindings['similarity'] ?? [];

        $course = $assessment->course;
        $totalQuestions = $assessment->questions->count();
        $totalMarks = (float) $assessment->total_marks;

        $overallScore = $report->overall_score !== null ? (float) $report->overall_score : 0.0;
        $overallRating = $this->reportService->getRatingLabel($overallScore);

        $dimensions = [
            'topic_coverage' => [
                'name' => 'Topic Coverage',
                'score' => (float) ($report->topic_coverage_score ?? 0.0),
                'rating' => $this->reportService->getRatingLabel((float) ($report->topic_coverage_score ?? 0.0)),
                'weight' => '25%',
            ],
            'learning_outcome_alignment' => [
                'name' => 'Learning Outcome Alignment',
                'score' => (float) ($report->learning_outcome_alignment_score ?? 0.0),
                'rating' => $this->reportService->getRatingLabel((float) ($report->learning_outcome_alignment_score ?? 0.0)),
                'weight' => '25%',
            ],
            'difficulty_balance' => [
                'name' => 'Difficulty Balance',
                'score' => (float) ($report->difficulty_balance_score ?? 0.0),
                'rating' => $this->reportService->getRatingLabel((float) ($report->difficulty_balance_score ?? 0.0)),
                'weight' => '15%',
            ],
            'cognitive_diversity' => [
                'name' => 'Cognitive Diversity',
                'score' => (float) ($report->cognitive_level_balance_score ?? 0.0),
                'rating' => $this->reportService->getRatingLabel((float) ($report->cognitive_level_balance_score ?? 0.0)),
                'weight' => '15%',
            ],
            'question_diversity' => [
                'name' => 'Question Diversity',
                'score' => (float) (100.0 - (float) ($report->similarity_score ?? 0.0)),
                'rating' => $this->reportService->getRatingLabel((float) (100.0 - (float) ($report->similarity_score ?? 0.0))),
                'weight' => '10%',
            ],
            'marks_distribution' => [
                'name' => 'Marks Distribution',
                'score' => (float) ($qualityAnalysis['marks_distribution_score'] ?? 85.0),
                'rating' => $this->reportService->getRatingLabel((float) ($qualityAnalysis['marks_distribution_score'] ?? 85.0)),
                'weight' => '10%',
            ],
        ];

        // Format recommendations
        $recommendations = $report->recommendations->map(fn($r) => [
            'id' => $r->id,
            'category' => $r->category,
            'priority' => strtolower($r->priority),
            'status' => strtolower($r->status),
            'problem' => $r->problem,
            'recommendation' => $r->recommendation,
            'evidence' => $r->evidence,
            'explanation' => $r->explanation,
        ]);

        $latestPdf = $report->assessmentReports->first();

        return [
            'analysis_id' => $report->id,
            'analysis_version' => $report->analysis_version,
            'is_current' => (bool) $report->is_current,
            'analysis_status' => $report->analysis_status,
            'analyzed_at' => $report->analyzed_at?->toIso8601String() ?? $report->created_at->toIso8601String(),
            'assessment' => [
                'id' => $assessment->id,
                'title' => $assessment->title,
                'type' => $assessment->type,
                'total_marks' => $totalMarks,
                'total_questions' => $totalQuestions,
                'duration_minutes' => $assessment->duration_minutes,
                'course_id' => $course->id,
                'course_code' => $course->course_code,
                'course_name' => $course->course_name,
                'semester' => $course->semester,
                'academic_year' => $course->academic_year,
            ],
            'overall_quality' => [
                'score' => $overallScore,
                'rating' => $overallRating,
                'dimensions' => $dimensions,
            ],
            'report_summary' => [
                'total_questions' => $report->total_questions,
                'similar_questions_count' => $report->similar_questions_count,
            ],
            'findings' => $rawFindings['findings'] ?? $rawFindings['quality']['findings'] ?? [],
            'recommendations' => $recommendations,
            'has_report' => (bool) $latestPdf,
            'report_id' => $latestPdf?->id,
            'report_uuid' => $latestPdf?->report_uuid,
        ];
    }

    /**
     * Side-by-side comparison engine between two completed analyses.
     */
    public function compareAnalyses(int $leftId, int $rightId, User $user): array
    {
        if ($leftId === $rightId) {
            throw new Exception('Cannot compare an analysis with itself. Please select two distinct analyses.');
        }

        $left = AnalysisReport::with(['assessment.course'])->findOrFail($leftId);
        $right = AnalysisReport::with(['assessment.course'])->findOrFail($rightId);

        if ($left->assessment->course->user_id !== $user->id || $right->assessment->course->user_id !== $user->id) {
            throw new Exception('Unauthorized. You can only compare analyses of your own courses.');
        }

        if ($left->analysis_status !== 'completed' || $right->analysis_status !== 'completed') {
            throw new Exception('Both analyses must be completed to be compared.');
        }

        // Left is treated as Previous/Baseline, Right is treated as Current/Comparison target
        $diff = function (?float $current, ?float $previous): ?float {
            if ($current === null || $previous === null) {
                return null;
            }
            return round($current - $previous, 1);
        };

        $formatItem = function (AnalysisReport $r) {
            return [
                'id' => $r->id,
                'assessment_id' => $r->assessment_id,
                'assessment_title' => $r->assessment->title,
                'assessment_type' => $r->assessment->type,
                'course_code' => $r->assessment->course->course_code,
                'course_name' => $r->assessment->course->course_name,
                'version' => $r->analysis_version,
                'analyzed_at' => $r->analyzed_at?->format('Y-m-d H:i') ?? $r->created_at->format('Y-m-d H:i'),
                'overall_score' => $r->overall_score !== null ? (float) $r->overall_score : null,
                'topic_coverage_score' => $r->topic_coverage_score !== null ? (float) $r->topic_coverage_score : null,
                'learning_outcome_alignment_score' => $r->learning_outcome_alignment_score !== null ? (float) $r->learning_outcome_alignment_score : null,
                'difficulty_balance_score' => $r->difficulty_balance_score !== null ? (float) $r->difficulty_balance_score : null,
                'cognitive_level_balance_score' => $r->cognitive_level_balance_score !== null ? (float) $r->cognitive_level_balance_score : null,
                'similarity_score' => $r->similarity_score !== null ? (float) $r->similarity_score : null,
                'question_diversity_score' => $r->similarity_score !== null ? round(100.0 - (float) $r->similarity_score, 1) : null,
            ];
        };

        $leftData = $formatItem($left);
        $rightData = $formatItem($right);

        $changes = [
            'overall_score' => $diff($rightData['overall_score'], $leftData['overall_score']),
            'topic_coverage_score' => $diff($rightData['topic_coverage_score'], $leftData['topic_coverage_score']),
            'learning_outcome_alignment_score' => $diff($rightData['learning_outcome_alignment_score'], $leftData['learning_outcome_alignment_score']),
            'difficulty_balance_score' => $diff($rightData['difficulty_balance_score'], $leftData['difficulty_balance_score']),
            'cognitive_level_balance_score' => $diff($rightData['cognitive_level_balance_score'], $leftData['cognitive_level_balance_score']),
            'similarity_score' => $diff($rightData['similarity_score'], $leftData['similarity_score']),
            'question_diversity_score' => $diff($rightData['question_diversity_score'], $leftData['question_diversity_score']),
        ];

        // Semantic directional interpretations
        $interpretations = [];
        if ($changes['overall_score'] !== null) {
            if ($changes['overall_score'] > 0) {
                $interpretations[] = "The quality indicator increased by {$changes['overall_score']} points compared with the selected previous analysis.";
            } elseif ($changes['overall_score'] < 0) {
                $absChange = abs($changes['overall_score']);
                $interpretations[] = "The quality indicator decreased by {$absChange} points compared with the selected previous analysis.";
            } else {
                $interpretations[] = "The overall quality indicator remained identical across both analyses.";
            }
        }

        if ($changes['similarity_score'] !== null && abs($changes['similarity_score']) > 0) {
            if ($changes['similarity_score'] > 0) {
                $interpretations[] = "Historical similarity increased by {$changes['similarity_score']} points. Higher similarity indicates greater overlap with previous exam questions; faculty review is recommended.";
            } else {
                $absSim = abs($changes['similarity_score']);
                $interpretations[] = "Historical similarity decreased by {$absSim} points, reflecting greater question freshness.";
            }
        }

        $sameAssessment = $left->assessment_id === $right->assessment_id;
        $contextNotice = $sameAssessment
            ? "Comparing versions of the same assessment ({$left->assessment->title})."
            : "Comparing two different assessments ({$left->assessment->title} vs {$right->assessment->title}). Differences may reflect distinct assessment scopes, syllabus portions, or design purposes.";

        return [
            'is_same_assessment' => $sameAssessment,
            'context_notice' => $contextNotice,
            'left' => $leftData,
            'right' => $rightData,
            'changes' => $changes,
            'interpretations' => $interpretations,
        ];
    }

    /**
     * Get chronological quality trend data for a course/assessment series.
     */
    public function getTrendData(User $user, int $courseId, ?string $assessmentType = null): array
    {
        $course = Course::where('id', $courseId)->where('user_id', $user->id)->firstOrFail();

        $query = AnalysisReport::whereHas('assessment', function ($q) use ($courseId, $assessmentType) {
            $q->where('course_id', $courseId);
            if ($assessmentType) {
                $q->where('type', $assessmentType);
            }
        })
            ->with('assessment')
            ->where('analysis_status', 'completed')
            ->orderBy('analyzed_at', 'asc')
            ->orderBy('id', 'asc');

        $reports = $query->get();

        $series = $reports->map(function (AnalysisReport $r) {
            return [
                'analysis_id' => $r->id,
                'assessment_id' => $r->assessment_id,
                'assessment_title' => $r->assessment->title,
                'assessment_type' => $r->assessment->type,
                'version' => $r->analysis_version,
                'analyzed_at' => $r->analyzed_at?->format('Y-m-d') ?? $r->created_at->format('Y-m-d'),
                'overall_score' => (float) ($r->overall_score ?? 0.0),
                'topic_coverage_score' => (float) ($r->topic_coverage_score ?? 0.0),
                'learning_outcome_alignment_score' => (float) ($r->learning_outcome_alignment_score ?? 0.0),
                'difficulty_balance_score' => (float) ($r->difficulty_balance_score ?? 0.0),
                'cognitive_level_balance_score' => (float) ($r->cognitive_level_balance_score ?? 0.0),
                'similarity_score' => (float) ($r->similarity_score ?? 0.0),
            ];
        });

        // Accessible text summary
        $summaryText = 'No completed analyses available for trend evaluation.';
        if ($series->count() >= 2) {
            $first = $series->first();
            $last = $series->last();
            $summaryText = "Overall quality indicator moved from {$first['overall_score']} on {$first['analyzed_at']} to {$last['overall_score']} on {$last['analyzed_at']}.";
        } elseif ($series->count() === 1) {
            $summaryText = "Single completed analysis recorded on {$series->first()['analyzed_at']}. Additional analyses will populate the quality trend trajectory.";
        }

        return [
            'course' => [
                'id' => $course->id,
                'code' => $course->course_code,
                'name' => $course->course_name,
            ],
            'total_data_points' => $series->count(),
            'series' => $series,
            'summary_text' => $summaryText,
        ];
    }

    /**
     * Calculate summary improvement points across a course's timeline.
     */
    public function getImprovementSummary(User $user, int $courseId, ?string $assessmentType = null): array
    {
        $trend = $this->getTrendData($user, $courseId, $assessmentType);
        $series = collect($trend['series']);

        if ($series->count() < 2) {
            return [
                'has_sufficient_data' => false,
                'message' => 'At least two completed analyses are required to calculate trend improvements.',
            ];
        }

        $earliest = $series->first();
        $latest = $series->last();

        $calc = fn($metric) => round($latest[$metric] - $earliest[$metric], 1);

        return [
            'has_sufficient_data' => true,
            'baseline_date' => $earliest['analyzed_at'],
            'latest_date' => $latest['analyzed_at'],
            'improvements' => [
                'overall_score' => $calc('overall_score'),
                'topic_coverage' => $calc('topic_coverage_score'),
                'learning_outcome_alignment' => $calc('learning_outcome_alignment_score'),
                'difficulty_balance' => $calc('difficulty_balance_score'),
                'cognitive_diversity' => $calc('cognitive_level_balance_score'),
            ],
        ];
    }
}

