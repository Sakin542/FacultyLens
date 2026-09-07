<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\PreviousQuestion;
use App\Models\QuestionSimilarityMatch;
use App\Services\AiService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiAnalysisController extends Controller
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Check AI Service health status.
     */
    public function health(): JsonResponse
    {
        $health = $this->aiService->checkHealth();
        $isOk = ($health['status'] ?? '') === 'ok';

        return response()->json([
            'data' => $health,
        ], $isOk ? 200 : 503);
    }

    /**
     * Analyze academic document text via the Python FastAPI microservice.
     */
    public function analyze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['nullable', 'string', 'max:100'],
            'text' => ['required', 'string', 'min:3', 'max:50000'],
        ], [
            'text.required' => 'Academic text is required for analysis.',
            'text.min' => 'Academic text must be at least 3 characters long.',
            'text.max' => 'Academic text cannot exceed 50,000 characters.',
        ]);

        $documentType = $validated['document_type'] ?? 'question_paper';
        $text = $validated['text'];

        try {
            $analysisResult = $this->aiService->analyze($text, $documentType);

            return response()->json([
                'status' => 'success',
                'message' => 'Academic text analyzed successfully.',
                'data' => $analysisResult,
            ], 200);
        } catch (Exception $e) {
            Log::error('AI Analysis failed in controller: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze a single academic question across pedagogical categories and Bloom's levels.
     */
    public function analyzeQuestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:10000'],
            'course_topics' => ['nullable', 'array'],
            'course_topics.*' => ['string', 'max:255'],
        ]);

        try {
            $result = $this->aiService->analyzeQuestion(
                $validated['question'],
                $validated['course_topics'] ?? []
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Question analyzed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('AI Question Analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze a batch of academic questions.
     */
    public function analyzeQuestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.text' => ['required_without:questions.*.question_text', 'nullable', 'string', 'min:3'],
            'questions.*.question_text' => ['nullable', 'string'],
            'questions.*.number' => ['nullable', 'integer'],
            'course_topics' => ['nullable', 'array'],
            'course_topics.*' => ['string', 'max:255'],
        ]);

        try {
            $result = $this->aiService->analyzeQuestions(
                $validated['questions'],
                $validated['course_topics'] ?? []
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Questions batch analyzed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('AI Questions batch analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze and persist AI insights for all questions of a specific assessment.
     */
    public function analyzeAssessmentQuestions(Request $request, \App\Models\Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty ownership
        if ($assessment->course->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access to assessment questions.',
            ], 403);
        }

        $assessment->load(['course.learningOutcomes', 'questions']);
        $course = $assessment->course;

        // Collect course topics from learning outcomes and course metadata
        $courseTopics = [];
        if ($course->learningOutcomes && $course->learningOutcomes->isNotEmpty()) {
            $courseTopics = $course->learningOutcomes->pluck('description')->toArray();
        }

        $questions = $assessment->questions;

        if ($questions->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No questions found for this assessment to analyze.',
            ], 422);
        }

        $batchPayload = $questions->map(function ($q) {
            return [
                'number' => $q->question_number,
                'text' => $q->question_text,
            ];
        })->toArray();

        try {
            $aiResult = $this->aiService->analyzeQuestions($batchPayload, $courseTopics);

            // Update individual question models with AI insights
            $analyzedList = $aiResult['questions'] ?? [];
            foreach ($analyzedList as $idx => $analyzedItem) {
                $qModel = $questions->get($idx);
                if ($qModel) {
                    $qModel->update([
                        'ai_question_type' => $analyzedItem['classification']['question_type'] ?? null,
                        'ai_difficulty_level' => $analyzedItem['difficulty']['level'] ?? null,
                        'ai_cognitive_level' => $analyzedItem['cognitive_level']['level'] ?? null,
                        'ai_topics' => $analyzedItem['topics'] ?? [],
                        'ai_analysis_status' => 'completed',
                        'ai_analyzed_at' => now(),
                    ]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment questions analyzed and updated successfully.',
                'data' => $aiResult,
            ]);
        } catch (Exception $e) {
            // Update questions to failed status if needed
            $questions->each(function ($q) {
                $q->update(['ai_analysis_status' => 'failed']);
            });

            Log::error('Assessment question analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze Learning Outcome Alignment directly for provided questions and LOs.
     */
    public function analyzeAlignment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'learning_outcomes' => ['required', 'array', 'min:1'],
            'learning_outcomes.*.description' => ['required_without:learning_outcomes.*', 'nullable', 'string', 'min:3'],
            'learning_outcomes.*.code' => ['nullable', 'string', 'max:50'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.text' => ['required_without:questions.*.question_text', 'nullable', 'string', 'min:3'],
            'questions.*.question_text' => ['nullable', 'string'],
            'questions.*.number' => ['nullable'],
            'thresholds' => ['nullable', 'array'],
            'thresholds.strong' => ['nullable', 'numeric', 'between:0,1'],
            'thresholds.weak' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        try {
            $result = $this->aiService->analyzeAlignment(
                $validated['learning_outcomes'],
                $validated['questions'],
                $validated['thresholds'] ?? null,
                $validated['course_id'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Learning outcome alignment analyzed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('AI LO alignment analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze and persist Learning Outcome Alignment for a specific assessment.
     */
    public function analyzeAssessmentAlignment(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if ($assessment->course->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access to assessment.',
            ], 403);
        }

        $assessment->load(['course.learningOutcomes', 'questions', 'latestAnalysisReport']);
        $course = $assessment->course;

        $learningOutcomes = $course->learningOutcomes;
        if ($learningOutcomes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The course has no defined learning outcomes. Please add learning outcomes before running alignment analysis.',
            ], 422);
        }

        $questions = $assessment->questions;
        if ($questions->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No questions found for this assessment to analyze.',
            ], 422);
        }

        $formattedLos = $learningOutcomes->map(function ($lo) {
            return [
                'id' => $lo->id,
                'code' => $lo->code,
                'description' => $lo->description,
            ];
        })->toArray();

        $formattedQuestions = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'number' => $q->question_number,
                'text' => $q->question_text,
                'topics' => $q->ai_topics ?? [],
            ];
        })->toArray();

        $thresholds = $request->input('thresholds');

        try {
            $aiResult = $this->aiService->analyzeAlignment(
                $formattedLos,
                $formattedQuestions,
                $thresholds,
                $course->id
            );

            // Update question -> learning_outcome_id mapping for strong/weak matches if question matches an LO
            $qaList = $aiResult['question_alignment'] ?? [];
            foreach ($qaList as $qa) {
                $qId = $qa['question_id'] ?? null;
                $matchedLo = $qa['matched_learning_outcome'] ?? null;
                $status = $qa['alignment_status'] ?? 'NOT_ALIGNED';

                if ($qId && $matchedLo && !empty($matchedLo['id']) && in_array($status, ['STRONG', 'WEAK'])) {
                    $questionModel = $questions->firstWhere('id', $qId);
                    if ($questionModel && empty($questionModel->learning_outcome_id)) {
                        $questionModel->update([
                            'learning_outcome_id' => $matchedLo['id'],
                        ]);
                    }
                }
            }

            // Merge findings into AnalysisReport
            $existingFindings = $assessment->latestAnalysisReport?->findings ?? [];
            $updatedFindings = array_merge($existingFindings, [
                'alignment_findings' => $aiResult['findings'] ?? [],
                'learning_outcome_coverage' => $aiResult['learning_outcome_coverage'] ?? [],
            ]);

            $report = AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'learning_outcome_alignment_score' => $aiResult['overall_alignment_score'] ?? 0.0,
                    'total_questions' => $aiResult['total_questions'] ?? count($questions),
                    'findings' => $updatedFindings,
                    'analysis_status' => 'completed',
                    'analyzed_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment learning outcome alignment analyzed successfully.',
                'data' => [
                    'alignment' => $aiResult,
                    'report' => $report,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Assessment LO alignment analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze Semantic Similarity & Potential Duplicates directly for provided question sets.
     */
    public function analyzeSimilarity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'current_questions' => ['required', 'array', 'min:1'],
            'current_questions.*.text' => ['required_without:current_questions.*.question_text', 'nullable', 'string', 'min:3'],
            'current_questions.*.question_text' => ['nullable', 'string'],
            'current_questions.*.number' => ['nullable'],
            'previous_questions' => ['nullable', 'array'],
            'previous_questions.*.text' => ['required_without:previous_questions.*.question_text', 'nullable', 'string', 'min:3'],
            'previous_questions.*.question_text' => ['nullable', 'string'],
            'thresholds' => ['nullable', 'array'],
            'thresholds.duplicate' => ['nullable', 'numeric', 'between:0,1'],
            'thresholds.high' => ['nullable', 'numeric', 'between:0,1'],
            'thresholds.moderate' => ['nullable', 'numeric', 'between:0,1'],
            'top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $result = $this->aiService->analyzeSimilarity(
                $validated['current_questions'],
                $validated['previous_questions'] ?? [],
                $validated['thresholds'] ?? null,
                $validated['top_k'] ?? null,
                $validated['course_id'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Semantic similarity analyzed successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('AI Similarity analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Analyze and persist Semantic Similarity for a specific assessment against historical course questions.
     */
    public function analyzeAssessmentSimilarity(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if ($assessment->course->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access to assessment.',
            ], 403);
        }

        $assessment->load(['course.previousQuestions', 'questions', 'latestAnalysisReport']);
        $course = $assessment->course;

        $questions = $assessment->questions;
        if ($questions->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No questions found in this assessment to analyze for similarity.',
            ], 422);
        }

        $previousQuestions = $course->previousQuestions;

        $formattedCurrent = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'number' => $q->question_number,
                'text' => $q->question_text,
                'question_type' => $q->ai_question_type ?? $q->question_type,
                'cognitive_level' => $q->ai_cognitive_level ?? $q->cognitive_level,
                'topics' => $q->ai_topics ?? [],
            ];
        })->toArray();

        $formattedPrevious = $previousQuestions->map(function ($pq) {
            return [
                'id' => $pq->id,
                'text' => $pq->question_text,
                'source_year' => $pq->source_year,
                'source_assessment' => $pq->source_assessment,
                'question_type' => $pq->question_type,
                'cognitive_level' => $pq->cognitive_level,
            ];
        })->toArray();

        $thresholds = $request->input('thresholds');
        $topK = $request->input('top_k');

        try {
            $aiResult = $this->aiService->analyzeSimilarity(
                $formattedCurrent,
                $formattedPrevious,
                $thresholds,
                $topK,
                $course->id
            );

            // Merge findings into AnalysisReport
            $existingFindings = $assessment->latestAnalysisReport?->findings ?? [];
            $updatedFindings = array_merge($existingFindings, [
                'similarity_findings' => $aiResult['findings'] ?? [],
            ]);

            $similarQuestionsCount = ($aiResult['potential_duplicates_count'] ?? 0) + ($aiResult['highly_similar_count'] ?? 0);

            $report = AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'similarity_score' => $aiResult['average_similarity_score'] ?? 0.0,
                    'similar_questions_count' => $similarQuestionsCount,
                    'total_questions' => $aiResult['total_current_questions'] ?? count($questions),
                    'findings' => $updatedFindings,
                    'analysis_status' => 'completed',
                    'analyzed_at' => now(),
                ]
            );

            // Persist granular question similarity matches
            QuestionSimilarityMatch::where('analysis_report_id', $report->id)->delete();

            $resultsList = $aiResult['results'] ?? [];
            foreach ($resultsList as $qRes) {
                $cId = $qRes['current_question_id'] ?? null;
                $matchesList = $qRes['matches'] ?? [];

                foreach ($matchesList as $matchItem) {
                    $pId = $matchItem['previous_question_id'] ?? null;
                    if ($cId && $pId) {
                        QuestionSimilarityMatch::create([
                            'analysis_report_id' => $report->id,
                            'current_question_id' => $cId,
                            'previous_question_id' => $pId,
                            'similarity_score' => $matchItem['similarity_score'] ?? 0.0,
                            'similarity_status' => $matchItem['similarity_status'] ?? 'NOT_SIMILAR',
                            'reasoning' => $qRes['reasoning'] ?? null,
                        ]);
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment semantic similarity analyzed successfully.',
                'data' => [
                    'similarity' => $aiResult,
                    'report' => $report->load('similarityMatches'),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Assessment similarity analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Stateless direct assessment quality evaluation across 6 pedagogical dimensions.
     */
    public function analyzeQuality(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.text' => ['required', 'string', 'min:3'],
            'questions.*.marks' => ['nullable', 'numeric', 'min:0'],
            'questions.*.question_type' => ['nullable', 'string', 'max:100'],
            'questions.*.difficulty' => ['nullable', 'string', 'max:50'],
            'questions.*.cognitive_level' => ['nullable', 'string', 'max:50'],
            'questions.*.topics' => ['nullable', 'array'],
            'questions.*.learning_outcome_code' => ['nullable', 'string', 'max:50'],
            'topics' => ['nullable', 'array'],
            'topics.*.name' => ['required_with:topics', 'string', 'max:255'],
            'learning_outcomes' => ['nullable', 'array'],
            'learning_outcomes.*.code' => ['required_with:learning_outcomes', 'string', 'max:50'],
            'assessment' => ['nullable', 'array'],
            'weights' => ['nullable', 'array'],
            'difficulty_targets' => ['nullable', 'array'],
        ]);

        try {
            $result = $this->aiService->analyzeAssessmentQuality(
                $validated['assessment'] ?? [],
                $validated['questions'],
                $validated['topics'] ?? [],
                $validated['learning_outcomes'] ?? [],
                $validated['weights'] ?? null,
                $validated['difficulty_targets'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment quality evaluated successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Direct quality analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Evaluate and persist holistic Assessment Quality for a specific assessment.
     */
    public function analyzeAssessmentQuality(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if ($assessment->course->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access to assessment.',
            ], 403);
        }

        $assessment->load([
            'course.learningOutcomes',
            'course.materials',
            'questions.learningOutcome',
            'latestAnalysisReport.similarityMatches',
        ]);
        $course = $assessment->course;

        $questions = $assessment->questions;
        if ($questions->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No questions found in this assessment to evaluate quality.',
            ], 422);
        }

        // Build questions payload
        $similarityMatches = $assessment->latestAnalysisReport?->similarityMatches ?? collect();
        $formattedQuestions = $questions->map(function ($q) use ($similarityMatches) {
            $highestMatch = $similarityMatches->where('current_question_id', $q->id)->sortByDesc('similarity_score')->first();
            $simScore = $highestMatch ? (float) $highestMatch->similarity_score : null;
            $isDup = $highestMatch && in_array($highestMatch->similarity_status, ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR']);

            return [
                'id' => $q->id,
                'number' => $q->question_number,
                'text' => $q->question_text,
                'marks' => (float) ($q->marks ?? 1.0),
                'question_type' => $q->ai_question_type ?? $q->question_type,
                'difficulty' => $q->ai_difficulty_level ?? $q->difficulty_level,
                'cognitive_level' => $q->ai_cognitive_level ?? $q->cognitive_level,
                'topics' => $q->ai_topics ?? [],
                'learning_outcome_code' => $q->learningOutcome?->code,
                'similarity_score' => $simScore,
                'is_duplicate' => $isDup,
            ];
        })->toArray();

        // Gather topics from syllabus materials or unique detected topics
        $courseTopics = [];
        $topicNamesSeen = [];

        foreach ($course->materials as $mat) {
            if (!empty($mat->title) && !isset($topicNamesSeen[strtolower($mat->title)])) {
                $courseTopics[] = ['name' => $mat->title];
                $topicNamesSeen[strtolower($mat->title)] = true;
            }
        }

        // Also add unique topics detected in questions if not in materials
        foreach ($questions as $q) {
            if (!empty($q->ai_topics) && is_array($q->ai_topics)) {
                foreach ($q->ai_topics as $tName) {
                    $clean = trim((string) $tName);
                    if ($clean && !isset($topicNamesSeen[strtolower($clean)])) {
                        $courseTopics[] = ['name' => $clean];
                        $topicNamesSeen[strtolower($clean)] = true;
                    }
                }
            }
        }

        // Format LOs
        $formattedLos = $course->learningOutcomes->map(function ($lo) {
            return [
                'code' => $lo->code,
                'description' => $lo->description,
            ];
        })->toArray();

        $assessmentData = [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'total_marks' => $assessment->total_marks,
        ];

        $weights = $request->input('weights');
        $targets = $request->input('difficulty_targets');

        try {
            $aiResult = $this->aiService->analyzeAssessmentQuality(
                $assessmentData,
                $formattedQuestions,
                $courseTopics,
                $formattedLos,
                $weights,
                $targets
            );

            // Merge findings into AnalysisReport
            $existingFindings = $assessment->latestAnalysisReport?->findings ?? [];
            $updatedFindings = array_merge($existingFindings, [
                'quality_engine' => [
                    'rating' => $aiResult['rating'] ?? 'NEEDS_REVIEW',
                    'weights_applied' => $aiResult['weights_applied'] ?? [],
                    'excluded_components' => $aiResult['excluded_components'] ?? [],
                    'components' => $aiResult['components'] ?? [],
                    'findings' => $aiResult['findings'] ?? [],
                    'topic_analysis' => $aiResult['topic_analysis'] ?? null,
                    'learning_outcome_analysis' => $aiResult['learning_outcome_analysis'] ?? null,
                    'difficulty_analysis' => $aiResult['difficulty_analysis'] ?? null,
                    'cognitive_analysis' => $aiResult['cognitive_analysis'] ?? null,
                    'question_diversity_analysis' => $aiResult['question_diversity_analysis'] ?? null,
                    'marks_analysis' => $aiResult['marks_analysis'] ?? null,
                ],
                'quality_findings' => $aiResult['findings'] ?? [],
            ]);

            $components = $aiResult['components'] ?? [];

            $report = AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'overall_score' => $aiResult['overall_quality_score'] ?? 0.0,
                    'topic_coverage_score' => $components['topic_coverage'] ?? null,
                    'learning_outcome_alignment_score' => $components['learning_outcome_coverage'] ?? null,
                    'difficulty_balance_score' => $components['difficulty_balance'] ?? null,
                    'cognitive_level_balance_score' => $components['cognitive_diversity'] ?? null,
                    'total_questions' => count($questions),
                    'findings' => $updatedFindings,
                    'analysis_status' => 'completed',
                    'analyzed_at' => now(),
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment quality evaluated successfully.',
                'data' => [
                    'quality' => $aiResult,
                    'report' => $report,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Assessment quality analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}



