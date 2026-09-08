<?php

namespace App\Jobs;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\PreviousQuestion;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Services\AiService;
use App\Services\AuditLogService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job for running AI assessment analysis asynchronously.
 * Moves expensive FastAPI inference out of the HTTP request cycle.
 * Idempotent: checks current status before proceeding.
 */
class AnalyzeAssessmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60];
    public int $timeout = 180;

    public function __construct(
        protected Assessment $assessment,
        protected int $userId
    ) {}

    public function handle(AiService $aiService, AuditLogService $auditLogService): void
    {
        $assessment = Assessment::with([
            'course.learningOutcomes',
            'course.materials',
            'questions.learningOutcome',
        ])->find($this->assessment->id);

        if (!$assessment) {
            Log::warning("AnalyzeAssessmentJob: assessment {$this->assessment->id} not found - skipping.");
            return;
        }

        $report = AnalysisReport::where('assessment_id', $assessment->id)->first();
        if ($report && $report->analysis_status === 'completed') {
            Log::info("AnalyzeAssessmentJob: assessment {$assessment->id} already completed - skipping.");
            return;
        }

        AnalysisReport::updateOrCreate(
            ['assessment_id' => $assessment->id],
            ['analysis_status' => 'processing', 'processing_error' => null]
        );

        $questions = $assessment->questions()->orderBy('question_number')->get();
        if ($questions->isEmpty()) {
            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                ['analysis_status' => 'failed', 'processing_error' => 'Assessment has no questions to analyze.']
            );
            return;
        }

        $course  = $assessment->course;
        $topics  = [];
        $seen    = [];

        foreach ($course->materials as $mat) {
            if (!empty($mat->title) && !isset($seen[strtolower($mat->title)])) {
                $topics[] = $mat->title;
                $seen[strtolower($mat->title)] = true;
            }
        }
        foreach ($questions as $q) {
            if (!empty($q->ai_topics) && is_array($q->ai_topics)) {
                foreach ($q->ai_topics as $tName) {
                    $clean = trim((string) $tName);
                    if ($clean && !isset($seen[strtolower($clean)])) {
                        $topics[] = $clean;
                        $seen[strtolower($clean)] = true;
                    }
                }
            }
        }

        $prevQuestions = PreviousQuestion::where('course_id', $course->id)->get();

        $formattedQuestions = $questions->map(function ($q, $idx) {
            return [
                'id'                    => $q->id,
                'number'                => $q->question_number ?? ($idx + 1),
                'text'                  => $q->question_text,
                'marks'                 => (float) ($q->marks ?? 1.0),
                'question_type'         => $q->ai_question_type ?? $q->question_type,
                'difficulty'            => $q->ai_difficulty_level ?? $q->difficulty_level,
                'cognitive_level'       => $q->ai_cognitive_level ?? $q->cognitive_level,
                'topics'                => is_array($q->ai_topics) ? $q->ai_topics : [],
                'learning_outcome_code' => $q->learningOutcome?->code,
            ];
        })->toArray();

        $formattedLos = $course->learningOutcomes->map(function ($lo, $idx) {
            return [
                'id'          => $lo->id,
                'code'        => $lo->code ?? ('LO' . ($idx + 1)),
                'description' => $lo->description,
            ];
        })->toArray();

        $formattedPrev = $prevQuestions->map(function ($pq, $idx) {
            return [
                'id'               => $pq->id,
                'number'           => $pq->question_number ?? ($idx + 1),
                'text'             => $pq->question_text,
                'assessment_title' => $pq->source_exam_name,
                'term'             => $pq->term,
                'year'             => $pq->academic_year,
            ];
        })->toArray();

        $payload = [
            'course_id'          => $course->id,
            'course_name'        => $course->course_name,
            'assessment'         => [
                'id'           => $assessment->id,
                'title'        => $assessment->title,
                'total_marks'  => (float) $assessment->total_marks,
                'course_code'  => $course->course_code,
                'course_title' => $course->course_name,
            ],
            'questions'          => $formattedQuestions,
            'learning_outcomes'  => $formattedLos,
            'course_topics'      => $topics,
            'previous_questions' => $formattedPrev,
        ];

        $auditLogService->log('AI_ANALYSIS_STARTED', $assessment, $assessment->id, [
            'assessment_title' => $assessment->title,
            'course_id'        => $assessment->course_id,
            'questions_count'  => count($questions),
        ]);

        try {
            $aiResult = $aiService->analyzeAssessment($payload);

            if (empty($aiResult) || ($aiResult['status'] ?? '') !== 'success' || !isset($aiResult['quality_analysis'])) {
                throw new Exception('Invalid AI response: missing quality_analysis or status not success.');
            }

            $qualityAnalysis     = $aiResult['quality_analysis'] ?? [];
            $recommendationsData = $aiResult['recommendations'] ?? [];
            $alignmentAnalysis   = $aiResult['alignment_analysis'] ?? [];
            $similarityAnalysis  = $aiResult['similarity_analysis'] ?? [];
            $components          = $qualityAnalysis['components'] ?? [];

            DB::transaction(function () use (
                $assessment, $questions, $prevQuestions,
                $aiResult, $qualityAnalysis, $recommendationsData,
                $alignmentAnalysis, $similarityAnalysis, $components
            ) {
                $similarCount = ($similarityAnalysis['potential_duplicates_count'] ?? 0)
                    + ($similarityAnalysis['highly_similar_count'] ?? 0);

                $findingsPayload = [
                    'summary'        => $aiResult['summary'] ?? [],
                    'quality'        => $qualityAnalysis,
                    'quality_engine' => $qualityAnalysis,
                    'alignment'      => $alignmentAnalysis,
                    'similarity'     => $similarityAnalysis,
                ];

                $existingCompleted = AnalysisReport::where('assessment_id', $assessment->id)
                    ->where('analysis_status', 'completed')
                    ->orderByDesc('analysis_version')
                    ->first();

                if ($existingCompleted) {
                    $nextVersion = $existingCompleted->analysis_version + 1;
                    AnalysisReport::where('assessment_id', $assessment->id)->update(['is_current' => false]);
                    $report = AnalysisReport::create([
                        'assessment_id'                    => $assessment->id,
                        'analysis_version'                 => $nextVersion,
                        'is_current'                       => true,
                        'overall_score'                    => (float) ($qualityAnalysis['overall_quality_score'] ?? 0.0),
                        'topic_coverage_score'             => (float) ($components['topic_coverage'] ?? 0.0),
                        'learning_outcome_alignment_score' => (float) ($components['learning_outcome_coverage'] ?? ($alignmentAnalysis['overall_alignment_score'] ?? 0.0)),
                        'difficulty_balance_score'         => (float) ($components['difficulty_balance'] ?? 0.0),
                        'cognitive_level_balance_score'    => (float) ($components['cognitive_diversity'] ?? 0.0),
                        'similarity_score'                 => (float) ($similarityAnalysis['average_similarity_score'] ?? 0.0),
                        'similar_questions_count'          => $similarCount,
                        'total_questions'                  => count($questions),
                        'analysis_status'                  => 'completed',
                        'processing_error'                 => null,
                        'findings'                         => $findingsPayload,
                        'analyzed_at'                      => now(),
                    ]);
                } else {
                    $report = AnalysisReport::updateOrCreate(
                        ['assessment_id' => $assessment->id, 'analysis_version' => 1],
                        [
                            'analysis_version'                 => 1,
                            'is_current'                       => true,
                            'overall_score'                    => (float) ($qualityAnalysis['overall_quality_score'] ?? 0.0),
                            'topic_coverage_score'             => (float) ($components['topic_coverage'] ?? 0.0),
                            'learning_outcome_alignment_score' => (float) ($components['learning_outcome_coverage'] ?? ($alignmentAnalysis['overall_alignment_score'] ?? 0.0)),
                            'difficulty_balance_score'         => (float) ($components['difficulty_balance'] ?? 0.0),
                            'cognitive_level_balance_score'    => (float) ($components['cognitive_diversity'] ?? 0.0),
                            'similarity_score'                 => (float) ($similarityAnalysis['average_similarity_score'] ?? 0.0),
                            'similar_questions_count'          => $similarCount,
                            'total_questions'                  => count($questions),
                            'analysis_status'                  => 'completed',
                            'processing_error'                 => null,
                            'findings'                         => $findingsPayload,
                            'analyzed_at'                      => now(),
                        ]
                    );
                }

                // Update question AI fields
                $qAnalysisList = $aiResult['questions_analysis']['questions'] ?? [];
                $qAnalysisMap  = [];
                foreach ($qAnalysisList as $qa) {
                    $qNum = $qa['number'] ?? $qa['question_number'] ?? null;
                    if ($qNum) {
                        $qAnalysisMap[$qNum] = $qa;
                    }
                }
                foreach ($questions as $q) {
                    $qa = $qAnalysisMap[$q->question_number] ?? null;
                    if ($qa) {
                        $q->ai_cognitive_level  = $qa['cognitive_level']['level'] ?? $q->ai_cognitive_level;
                        $q->ai_difficulty_level = $qa['difficulty']['level'] ?? $q->ai_difficulty_level;
                        $q->ai_question_type    = $qa['classification']['type'] ?? ($qa['classification']['question_type'] ?? $q->ai_question_type);
                        $q->ai_topics = array_map(function ($t) {
                            return is_array($t) ? ($t['name'] ?? '') : (string) $t;
                        }, $qa['topics'] ?? []);
                        $q->ai_analysis_status = 'completed';
                        $q->ai_analyzed_at     = now();
                        $q->save();
                    }
                }

                // Persist similarity matches
                QuestionSimilarityMatch::where('analysis_report_id', $report->id)->delete();
                if (!empty($similarityAnalysis['matches'])) {
                    $qByNumber = $questions->keyBy('question_number');
                    $pqById    = $prevQuestions->keyBy('id');
                    foreach ($similarityAnalysis['matches'] as $matchGroup) {
                        $cNum      = $matchGroup['current_question_number'] ?? null;
                        $cQuestion = $qByNumber->get($cNum);
                        if ($cQuestion && !empty($matchGroup['matches'])) {
                            foreach ($matchGroup['matches'] as $m) {
                                $pId = $m['previous_question_id'] ?? null;
                                if ($pId && $pqById->has($pId)) {
                                    QuestionSimilarityMatch::create([
                                        'analysis_report_id'   => $report->id,
                                        'current_question_id'  => $cQuestion->id,
                                        'previous_question_id' => $pId,
                                        'similarity_score'     => $m['similarity_score'] ?? 0.0,
                                        'similarity_status'    => $m['similarity_status'] ?? 'NOT_SIMILAR',
                                        'reasoning'            => $matchGroup['reasoning'] ?? null,
                                    ]);
                                }
                            }
                        }
                    }
                }

                // Persist LO alignments
                QuestionLearningOutcomeAlignment::where('analysis_report_id', $report->id)->delete();
                $qaList = $alignmentAnalysis['question_alignment'] ?? [];
                if (!empty($qaList) && $assessment->course->learningOutcomes->isNotEmpty()) {
                    $loById = $assessment->course->learningOutcomes->keyBy('id');
                    $qById  = $questions->keyBy('id');
                    foreach ($qaList as $qaItem) {
                        $qId       = $qaItem['question_id'] ?? null;
                        $matchedLo = $qaItem['matched_learning_outcome'] ?? null;
                        $loId      = $matchedLo['id'] ?? null;
                        if ($qId && $loId && $qById->has($qId) && $loById->has($loId)) {
                            QuestionLearningOutcomeAlignment::create([
                                'analysis_report_id'  => $report->id,
                                'question_id'         => $qId,
                                'learning_outcome_id' => $loId,
                                'similarity_score'    => $qaItem['alignment_score'] ?? ($matchedLo['similarity_score'] ?? 0.0),
                                'alignment'           => $qaItem['alignment_status'] ?? 'NOT_ALIGNED',
                                'reasoning'           => $qaItem['reasoning'] ?? null,
                            ]);
                        }
                    }
                }

                // Persist recommendations (preserve faculty decision status)
                $newRecList = $recommendationsData['recommendations'] ?? [];
                if (!empty($newRecList)) {
                    $existingRecs = Recommendation::whereHas('analysisReport', function ($q) use ($assessment) {
                        $q->where('assessment_id', $assessment->id);
                    })->get()->keyBy('title');
                    $persistedIds = [];

                    foreach ($newRecList as $rec) {
                        $title        = $rec['problem'] ?? 'Assessment Recommendation';
                        $existing     = $existingRecs->get($title);
                        $status       = $existing ? $existing->status : 'pending';
                        $facultyNotes = $existing ? $existing->faculty_notes : null;

                        $saved = Recommendation::updateOrCreate(
                            ['analysis_report_id' => $report->id, 'title' => $title],
                            [
                                'category'       => $rec['category'] ?? 'general',
                                'problem'        => $rec['problem'] ?? $title,
                                'description'    => $rec['recommendation'] ?? '',
                                'explanation'    => $rec['explanation'] ?? '',
                                'recommendation' => $rec['recommendation'] ?? '',
                                'evidence'       => $rec['evidence'] ?? null,
                                'source_metric'  => $rec['source_metric'] ?? '',
                                'priority'       => strtolower($rec['priority'] ?? 'medium'),
                                'status'         => $status,
                                'faculty_notes'  => $facultyNotes,
                            ]
                        );
                        $persistedIds[] = $saved->id;
                    }

                    Recommendation::where('analysis_report_id', $report->id)
                        ->where('status', 'pending')
                        ->whereNotIn('id', $persistedIds)
                        ->delete();
                }
            });

            Cache::forget("user:{$this->userId}:assessment:{$assessment->id}:analysis");

            $auditLogService->log('AI_ANALYSIS_COMPLETED', $assessment, $assessment->id, [
                'assessment_title'   => $assessment->title,
                'overall_score'      => $aiResult['quality_analysis']['overall_quality_score'] ?? null,
                'questions_analyzed' => count($questions),
            ]);

            Log::info("AnalyzeAssessmentJob: completed for assessment {$assessment->id}.");
        } catch (Exception $e) {
            Log::error("AnalyzeAssessmentJob: failed for assessment {$assessment->id}: {$e->getMessage()}");

            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                ['analysis_status' => 'failed', 'processing_error' => $e->getMessage()]
            );

            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error("AnalyzeAssessmentJob: permanently failed for assessment {$this->assessment->id}: {$exception->getMessage()}");

        AnalysisReport::updateOrCreate(
            ['assessment_id' => $this->assessment->id],
            [
                'analysis_status'  => 'failed',
                'processing_error' => 'Analysis failed after maximum retry attempts: ' . $exception->getMessage(),
            ]
        );
    }
}
