<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeAssessmentJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\DocumentProcessing;
use App\Models\PreviousQuestion;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Services\AiService;
use App\Services\AuditLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiAnalysisController extends Controller
{
    protected AiService $aiService;
    protected AuditLogService $auditLogService;

    public function __construct(AiService $aiService, AuditLogService $auditLogService)
    {
        $this->aiService = $aiService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * Map exception messages to appropriate HTTP status codes (504 for timeout, 422 for validation, 502 for connection/service).
     */
    protected function determineErrorStatus(Exception $e): int
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 504;
        }
        if (str_contains($msg, 'validation') || str_contains($msg, 'required') || str_contains($msg, 'empty')) {
            return 422;
        }
        return 502;
    }

    /**
     * Validate structured AI response ranges and integrity before database persistence.
     */
    protected function validateUnifiedAiResponse(array $response): void
    {
        if (empty($response) || !isset($response['status']) || $response['status'] !== 'success') {
            throw new Exception('Invalid AI response: status is not success.');
        }

        if (!isset($response['quality_analysis'])) {
            throw new Exception('Invalid AI response: missing quality_analysis payload.');
        }

        $overallScore = $response['quality_analysis']['overall_quality_score'] ?? null;
        if ($overallScore !== null && ($overallScore < 0 || $overallScore > 100)) {
            throw new Exception("Invalid overall quality score: {$overallScore}. Expected range is 0 to 100.");
        }
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
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Analyze a previously uploaded and processed document.
     * Verifies user authorization, processing status, and passes cleaned text to AI Service.
     */
    public function analyzeDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => ['required_without:text', 'nullable', 'integer', 'exists:document_processings,id'],
            'text' => ['required_without:document_id', 'nullable', 'string', 'min:3'],
            'document_type' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $text = $validated['text'] ?? null;
        $documentType = $validated['document_type'] ?? 'question_paper';

        if (!empty($validated['document_id'])) {
            $document = DocumentProcessing::find($validated['document_id']);

            if (!$document || !$user->can('view', $document)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to document.',
                ], 403);
            }

            if ($document->processing_status !== 'completed') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Document has not completed processing yet. Current status: ' . $document->processing_status,
                ], 422);
            }

            $text = $document->cleaned_text ?: $document->extracted_text;
            if (empty(trim((string) $text))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Document contains no extractable text for AI analysis.',
                ], 422);
            }

            $documentType = $document->document_type ?? $documentType;
        }

        try {
            $analysisResult = $this->aiService->sendDocument($text, $documentType);

            return response()->json([
                'status' => 'success',
                'message' => 'Document analyzed successfully by AI service.',
                'data' => $analysisResult,
            ], 200);
        } catch (Exception $e) {
            Log::error('AI Document Analysis failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
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
        if (!$user->can('analyze', $assessment)) {
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

            DB::transaction(function () use ($questions, $aiResult) {
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
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment questions analyzed and updated successfully.',
                'data' => $aiResult,
            ]);
        } catch (Exception $e) {
            $questions->each(function ($q) {
                $q->update(['ai_analysis_status' => 'failed']);
            });

            Log::error('Assessment question analysis failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
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
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Analyze and persist Learning Outcome Alignment for a specific assessment.
     */
    public function analyzeAssessmentAlignment(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if (!$user->can('analyze', $assessment)) {
            return response()->json([
                'status' => 'error',
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

            $report = DB::transaction(function () use ($assessment, $questions, $learningOutcomes, $aiResult) {
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

                $savedReport = AnalysisReport::updateOrCreate(
                    ['assessment_id' => $assessment->id],
                    [
                        'learning_outcome_alignment_score' => $aiResult['overall_alignment_score'] ?? 0.0,
                        'total_questions' => $aiResult['total_questions'] ?? count($questions),
                        'findings' => $updatedFindings,
                        'analysis_status' => 'completed',
                        'processing_error' => null,
                        'analyzed_at' => now(),
                    ]
                );

                // Persist granular QuestionLearningOutcomeAlignment records
                QuestionLearningOutcomeAlignment::where('analysis_report_id', $savedReport->id)->delete();
                $loById = $learningOutcomes->keyBy('id');
                $qById = $questions->keyBy('id');

                foreach ($qaList as $qa) {
                    $qId = $qa['question_id'] ?? null;
                    $matchedLo = $qa['matched_learning_outcome'] ?? null;
                    $loId = $matchedLo['id'] ?? null;

                    if ($qId && $loId && $qById->has($qId) && $loById->has($loId)) {
                        QuestionLearningOutcomeAlignment::create([
                            'analysis_report_id' => $savedReport->id,
                            'question_id' => $qId,
                            'learning_outcome_id' => $loId,
                            'similarity_score' => $qa['alignment_score'] ?? ($matchedLo['similarity_score'] ?? 0.0),
                            'alignment' => $qa['alignment_status'] ?? 'NOT_ALIGNED',
                            'reasoning' => $qa['reasoning'] ?? null,
                        ]);
                    }
                }

                return $savedReport;
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment learning outcome alignment analyzed successfully.',
                'data' => [
                    'alignment' => $aiResult,
                    'report' => $report->load('learningOutcomeAlignments'),
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Assessment LO alignment analysis failed: ' . $e->getMessage());

            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'analysis_status' => 'failed',
                    'processing_error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
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
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Analyze and persist Semantic Similarity for a specific assessment against historical course questions.
     */
    public function analyzeAssessmentSimilarity(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if (!$user->can('analyze', $assessment)) {
            return response()->json([
                'status' => 'error',
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

            $report = DB::transaction(function () use ($assessment, $questions, $aiResult) {
                // Merge findings into AnalysisReport
                $existingFindings = $assessment->latestAnalysisReport?->findings ?? [];
                $updatedFindings = array_merge($existingFindings, [
                    'similarity_findings' => $aiResult['findings'] ?? [],
                ]);

                $similarQuestionsCount = ($aiResult['potential_duplicates_count'] ?? 0) + ($aiResult['highly_similar_count'] ?? 0);

                $savedReport = AnalysisReport::updateOrCreate(
                    ['assessment_id' => $assessment->id],
                    [
                        'similarity_score' => $aiResult['average_similarity_score'] ?? 0.0,
                        'similar_questions_count' => $similarQuestionsCount,
                        'total_questions' => $aiResult['total_current_questions'] ?? count($questions),
                        'findings' => $updatedFindings,
                        'analysis_status' => 'completed',
                        'processing_error' => null,
                        'analyzed_at' => now(),
                    ]
                );

                // Persist granular question similarity matches
                QuestionSimilarityMatch::where('analysis_report_id', $savedReport->id)->delete();

                $resultsList = $aiResult['results'] ?? [];
                foreach ($resultsList as $qRes) {
                    $cId = $qRes['current_question_id'] ?? null;
                    $matchesList = $qRes['matches'] ?? [];

                    foreach ($matchesList as $matchItem) {
                        $pId = $matchItem['previous_question_id'] ?? null;
                        if ($cId && $pId) {
                            QuestionSimilarityMatch::create([
                                'analysis_report_id' => $savedReport->id,
                                'current_question_id' => $cId,
                                'previous_question_id' => $pId,
                                'similarity_score' => $matchItem['similarity_score'] ?? 0.0,
                                'similarity_status' => $matchItem['similarity_status'] ?? 'NOT_SIMILAR',
                                'reasoning' => $qRes['reasoning'] ?? null,
                            ]);
                        }
                    }
                }

                return $savedReport;
            });

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

            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'analysis_status' => 'failed',
                    'processing_error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
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
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Evaluate and persist holistic Assessment Quality for a specific assessment.
     */
    public function analyzeAssessmentQuality(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check faculty authorization
        if (!$user->can('analyze', $assessment)) {
            return response()->json([
                'status' => 'error',
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

            $report = DB::transaction(function () use ($assessment, $questions, $aiResult, $updatedFindings, $components) {
                return AnalysisReport::updateOrCreate(
                    ['assessment_id' => $assessment->id],
                    [
                        'overall_score' => (float) ($aiResult['overall_quality_score'] ?? 0.0),
                        'topic_coverage_score' => (float) ($components['topic_coverage'] ?? 0.0),
                        'learning_outcome_alignment_score' => (float) ($components['learning_outcome_coverage'] ?? 0.0),
                        'difficulty_balance_score' => (float) ($components['difficulty_balance'] ?? 0.0),
                        'cognitive_level_balance_score' => (float) ($components['cognitive_diversity'] ?? 0.0),
                        'total_questions' => count($questions),
                        'findings' => $updatedFindings,
                        'analysis_status' => 'completed',
                        'processing_error' => null,
                        'analyzed_at' => now(),
                    ]
                );
            });

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

            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'analysis_status' => 'failed',
                    'processing_error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Direct stateless recommendation generation.
     */
    public function generateRecommendations(Request $request): JsonResponse
    {
        $payload = $request->all();

        try {
            $result = $this->aiService->generateRecommendations($payload);

            return response()->json([
                'status' => 'success',
                'message' => 'Recommendations generated successfully.',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            Log::error('Direct recommendation generation failed: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Generate and persist prioritized recommendations for a specific assessment.
     */
    public function generateAssessmentRecommendations(Request $request, Assessment $assessment): JsonResponse
    {
        // Multi-tenant check
        if (!$request->user()->can('analyze', $assessment)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access to this assessment.',
            ], 403);
        }

        $course = $assessment->course;
        $questions = $assessment->questions;
        $report = $assessment->latestAnalysisReport;

        $findings = $report?->findings ?? [];
        $qualityFindings = $findings['quality_engine'] ?? [];

        // Build Payload from available analyses or calculate on the fly
        $assessmentData = [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'total_marks' => $assessment->total_marks,
            'course_code' => $course->course_code,
            'course_title' => $course->course_name,
        ];

        // 1. Topic analysis
        $topicAnalysis = $qualityFindings['topic_analysis'] ?? null;
        if (!$topicAnalysis) {
            $topicsSeen = [];
            $topicItems = [];
            foreach ($course->materials as $mat) {
                if (!empty($mat->title) && !isset($topicsSeen[strtolower($mat->title)])) {
                    $topicsSeen[strtolower($mat->title)] = true;
                    $topicItems[] = [
                        'name' => $mat->title,
                        'status' => 'NOT_COVERED',
                        'question_count' => 0,
                        'coverage_percentage' => 0.0,
                    ];
                }
            }
            if (!empty($topicItems)) {
                $topicAnalysis = [
                    'status' => 'EVALUATED',
                    'total_topics' => count($topicItems),
                    'covered_topics' => 0,
                    'uncovered_topics' => count($topicItems),
                    'coverage_percentage' => 0.0,
                    'topics' => $topicItems,
                ];
            }
        }

        // 2. LO analysis
        $loAnalysis = $qualityFindings['learning_outcome_analysis'] ?? null;
        if (!$loAnalysis && $course->learningOutcomes->isNotEmpty()) {
            $loItems = $course->learningOutcomes->map(function ($lo) {
                return [
                    'code' => $lo->code,
                    'description' => $lo->description,
                    'status' => 'NOT_COVERED',
                    'question_count' => 0,
                    'strong_matches_count' => 0,
                    'weak_matches_count' => 0,
                    'max_similarity' => 0.0,
                ];
            })->toArray();

            $loAnalysis = [
                'status' => 'EVALUATED',
                'total_los' => count($loItems),
                'covered_los' => 0,
                'weakly_covered_los' => 0,
                'uncovered_los' => count($loItems),
                'coverage_percentage' => 0.0,
                'learning_outcomes' => $loItems,
            ];
        }

        // 3. Difficulty analysis
        $difficultyAnalysis = $qualityFindings['difficulty_analysis'] ?? null;

        // 4. Cognitive analysis
        $cognitiveAnalysis = $qualityFindings['cognitive_analysis'] ?? null;

        // 5. Question diversity analysis
        $questionDiversityAnalysis = $qualityFindings['question_diversity_analysis'] ?? null;

        // 6. Marks analysis
        $marksAnalysis = $qualityFindings['marks_analysis'] ?? null;
        if (!$marksAnalysis && $assessment->total_marks > 0) {
            $actualSum = (float) $questions->sum('marks');
            $expectedTotal = (float) $assessment->total_marks;
            $marksAnalysis = [
                'status' => abs($expectedTotal - $actualSum) < 0.01 ? 'MATCHED' : 'MISMATCH',
                'total_marks_expected' => $expectedTotal,
                'total_marks_actual' => $actualSum,
                'marks_match' => abs($expectedTotal - $actualSum) < 0.01,
                'discrepancy' => round($actualSum - $expectedTotal, 2),
                'max_single_question_percentage' => $expectedTotal > 0 ? round(($questions->max('marks') / $expectedTotal) * 100, 1) : 0,
                'has_mark_concentration' => $expectedTotal > 0 && ($questions->max('marks') / $expectedTotal) >= 0.40,
            ];
        }

        // 7. Similarity analysis
        $similarityMatches = $report?->similarityMatches ?? collect();
        $simMatchesData = [];
        foreach ($similarityMatches as $sm) {
            $simMatchesData[] = [
                'current_question_number' => $sm->currentQuestion?->question_number,
                'current_question_text' => $sm->currentQuestion?->question_text,
                'max_similarity_score' => (float) $sm->similarity_score,
                'max_similarity_status' => $sm->similarity_status,
                'matches' => [
                    [
                        'source_assessment' => $sm->previousQuestion?->source_exam_name ?? 'Past Question',
                        'previous_question_text' => $sm->previousQuestion?->question_text,
                        'similarity_score' => (float) $sm->similarity_score,
                        'similarity_status' => $sm->similarity_status,
                    ]
                ],
            ];
        }

        $similarityAnalysis = [
            'status' => $similarityMatches->isEmpty() ? 'NO_MATCHES' : 'EVALUATED',
            'potential_duplicates_count' => $similarityMatches->where('similarity_status', 'POTENTIAL_DUPLICATE')->count(),
            'highly_similar_count' => $similarityMatches->where('similarity_status', 'HIGHLY_SIMILAR')->count(),
            'matches' => $simMatchesData,
        ];

        // 8. Quality analysis
        $qualityAnalysis = [
            'overall_quality_score' => $report?->overall_score ? (float) $report->overall_score : null,
            'rating' => $qualityFindings['rating'] ?? null,
            'components' => $qualityFindings['components'] ?? null,
        ];

        $payload = [
            'assessment' => $assessmentData,
            'topic_analysis' => $topicAnalysis,
            'learning_outcome_analysis' => $loAnalysis,
            'difficulty_analysis' => $difficultyAnalysis,
            'cognitive_analysis' => $cognitiveAnalysis,
            'question_diversity_analysis' => $questionDiversityAnalysis,
            'marks_analysis' => $marksAnalysis,
            'similarity_analysis' => $similarityAnalysis,
            'quality_analysis' => $qualityAnalysis,
        ];

        try {
            $aiResponse = $this->aiService->generateRecommendations($payload);

            $allRecommendations = DB::transaction(function () use ($assessment, $questions, $aiResponse) {
                // Ensure AnalysisReport exists
                $report = AnalysisReport::firstOrCreate(
                    ['assessment_id' => $assessment->id],
                    [
                        'overall_score' => 0.0,
                        'total_questions' => count($questions),
                        'analysis_status' => 'completed',
                        'processing_error' => null,
                        'analyzed_at' => now(),
                    ]
                );

                // Persist recommendations
                // We preserve existing accepted/dismissed statuses if the same problem/title exists
                $existingRecs = Recommendation::where('analysis_report_id', $report->id)->get()->keyBy('title');

                $newRecList = $aiResponse['recommendations'] ?? [];
                $persistedIds = [];

                foreach ($newRecList as $rec) {
                    $title = $rec['problem'] ?? 'Assessment Recommendation';
                    $existing = $existingRecs->get($title);

                    $status = $existing ? $existing->status : 'pending';
                    $facultyNotes = $existing ? $existing->faculty_notes : null;

                    $saved = Recommendation::updateOrCreate(
                        [
                            'analysis_report_id' => $report->id,
                            'title' => $title,
                        ],
                        [
                            'category' => $rec['category'] ?? 'general',
                            'problem' => $rec['problem'] ?? $title,
                            'description' => $rec['recommendation'] ?? '',
                            'explanation' => $rec['explanation'] ?? '',
                            'recommendation' => $rec['recommendation'] ?? '',
                            'evidence' => $rec['evidence'] ?? null,
                            'source_metric' => $rec['source_metric'] ?? '',
                            'priority' => strtolower($rec['priority'] ?? 'medium'),
                            'status' => $status,
                            'faculty_notes' => $facultyNotes,
                        ]
                    );

                    $persistedIds[] = $saved->id;
                }

                // Clean up stale pending recommendations that no longer apply
                Recommendation::where('analysis_report_id', $report->id)
                    ->where('status', 'pending')
                    ->whereNotIn('id', $persistedIds)
                    ->delete();

                return Recommendation::where('analysis_report_id', $report->id)
                    ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
                    ->get();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Recommendations generated and updated successfully.',
                'data' => [
                    'summary' => [
                        'total_recommendations' => $allRecommendations->count(),
                        'high_priority_count' => $allRecommendations->where('priority', 'high')->count(),
                        'medium_priority_count' => $allRecommendations->where('priority', 'medium')->count(),
                        'low_priority_count' => $allRecommendations->where('priority', 'low')->count(),
                        'accepted_count' => $allRecommendations->where('status', 'accepted')->count(),
                        'dismissed_count' => $allRecommendations->where('status', 'dismissed')->count(),
                        'pending_count' => $allRecommendations->where('status', 'pending')->count(),
                    ],
                    'recommendations' => $allRecommendations,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Assessment recommendation generation failed: ' . $e->getMessage());

            if ($report) {
                $report->update([
                    'analysis_status' => 'failed',
                    'processing_error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Retrieve persisted recommendations for an assessment.
     */
    public function getAssessmentRecommendations(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$request->user()->can('viewAnalysis', $assessment)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to assessment recommendations.'], 403);
        }

        $report = $assessment->latestAnalysisReport;
        if (!$report) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'summary' => [
                        'total_recommendations' => 0,
                        'high_priority_count' => 0,
                        'medium_priority_count' => 0,
                        'low_priority_count' => 0,
                        'accepted_count' => 0,
                        'dismissed_count' => 0,
                        'pending_count' => 0,
                    ],
                    'recommendations' => [],
                ],
            ]);
        }

        $recommendations = Recommendation::where('analysis_report_id', $report->id)
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => [
                    'total_recommendations' => $recommendations->count(),
                    'high_priority_count' => $recommendations->where('priority', 'high')->count(),
                    'medium_priority_count' => $recommendations->where('priority', 'medium')->count(),
                    'low_priority_count' => $recommendations->where('priority', 'low')->count(),
                    'accepted_count' => $recommendations->where('status', 'accepted')->count(),
                    'dismissed_count' => $recommendations->where('status', 'dismissed')->count(),
                    'pending_count' => $recommendations->where('status', 'pending')->count(),
                ],
                'recommendations' => $recommendations,
            ],
        ]);
    }

    /**
     * Update status of a recommendation (accept, dismiss, review, pending).
     */
    public function updateRecommendationStatus(Request $request, Recommendation $recommendation): JsonResponse
    {
        // Multi-tenant check
        $assessment = $recommendation->analysisReport?->assessment;
        if (!$assessment || !$request->user()->can('update', $recommendation)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access to this recommendation.',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,reviewed,accepted,dismissed,PENDING,REVIEWED,ACCEPTED,DISMISSED'],
            'faculty_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $status = strtolower($validated['status']);
        $recommendation->status = $status;
        if (array_key_exists('faculty_notes', $validated)) {
            $recommendation->faculty_notes = $validated['faculty_notes'];
        }
        $recommendation->save();

        return response()->json([
            'status' => 'success',
            'message' => "Recommendation status updated to {$status}.",
            'data' => $recommendation,
        ]);
    }

    /**
     * STEP 15 & 16: Unified AI Assessment Analysis endpoint.
     * Evaluates Questions, LO Alignment, Past Similarity, Quality Engine, and Recommendations in one consolidated run.
     */
    public function analyzeAssessment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_id' => ['nullable', 'integer', 'exists:assessments,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'questions' => ['nullable', 'array'],
            'questions.*.text' => ['required_with:questions', 'string'],
            'questions.*.marks' => ['nullable', 'numeric'],
            'learning_outcomes' => ['nullable', 'array'],
            'course_topics' => ['nullable', 'array'],
            'previous_questions' => ['nullable', 'array'],
            'weights' => ['nullable', 'array'],
            'difficulty_targets' => ['nullable', 'array'],
            'custom_rules' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $assessmentId = $validated['assessment_id'] ?? null;
        $assessment = null;
        $questions = collect();
        $prevQuestions = collect();

        if ($assessmentId) {
            $assessment = Assessment::with([
                'course.learningOutcomes',
                'course.materials',
                'questions.learningOutcome',
                'latestAnalysisReport.similarityMatches',
            ])->find($assessmentId);

            if (!$assessment || !$user->can('analyze', $assessment)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized access to assessment.',
                ], 403);
            }
        }

        // If running for a persisted assessment:
        if ($assessment) {
            $questions = $assessment->questions()->orderBy('question_number')->get();
            if ($questions->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Assessment has no questions to analyze.',
                ], 422);
            }

            // Prevent duplicate simultaneous AI analysis on the same assessment
            $activeReport = $assessment->latestAnalysisReport;
            if ($activeReport && $activeReport->analysis_status === 'processing' && $activeReport->updated_at && $activeReport->updated_at->diffInMinutes(now()) < 3) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Analysis is currently processing for this assessment. Please wait for completion.',
                ], 409);
            }

            // Set analysis report status to processing
            AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                [
                    'analysis_status' => 'processing',
                    'processing_error' => null,
                ]
            );

            // Audit log analysis start event
            $this->auditLogService->log(
                'AI_ANALYSIS_STARTED',
                $assessment,
                $assessment->id,
                [
                    'assessment_title' => $assessment->title,
                    'course_id' => $assessment->course_id,
                    'questions_count' => count($questions),
                ],
                $user
            );

            $course = $assessment->course;
            $topics = [];
            $topicNamesSeen = [];
            foreach ($course->materials as $mat) {
                if (!empty($mat->title) && !isset($topicNamesSeen[strtolower($mat->title)])) {
                    $topics[] = $mat->title;
                    $topicNamesSeen[strtolower($mat->title)] = true;
                }
            }
            foreach ($questions as $q) {
                if (!empty($q->ai_topics) && is_array($q->ai_topics)) {
                    foreach ($q->ai_topics as $tName) {
                        $clean = trim((string) $tName);
                        if ($clean && !isset($topicNamesSeen[strtolower($clean)])) {
                            $topics[] = $clean;
                            $topicNamesSeen[strtolower($clean)] = true;
                        }
                    }
                }
            }

            $prevQuestions = PreviousQuestion::where('course_id', $course->id)->get();

            $formattedQuestions = $questions->map(function ($q, $idx) {
                return [
                    'id' => $q->id,
                    'number' => $q->question_number ?? ($idx + 1),
                    'text' => $q->question_text,
                    'marks' => (float) ($q->marks ?? 1.0),
                    'question_type' => $q->ai_question_type ?? $q->question_type,
                    'difficulty' => $q->ai_difficulty_level ?? $q->difficulty_level,
                    'cognitive_level' => $q->ai_cognitive_level ?? $q->cognitive_level,
                    'topics' => is_array($q->ai_topics) ? $q->ai_topics : [],
                    'learning_outcome_code' => $q->learningOutcome?->code,
                ];
            })->toArray();

            $formattedLos = $course->learningOutcomes->map(function ($lo, $idx) {
                return [
                    'id' => $lo->id,
                    'code' => $lo->code ?? ('LO' . ($idx + 1)),
                    'description' => $lo->description,
                ];
            })->toArray();

            $formattedPrev = $prevQuestions->map(function ($pq, $idx) {
                return [
                    'id' => $pq->id,
                    'number' => $pq->question_number ?? ($idx + 1),
                    'text' => $pq->question_text,
                    'assessment_title' => $pq->source_exam_name,
                    'term' => $pq->term,
                    'year' => $pq->academic_year,
                ];
            })->toArray();

            $payload = [
                'course_id' => $course->id,
                'course_name' => $course->course_name,
                'assessment' => [
                    'id' => $assessment->id,
                    'title' => $assessment->title,
                    'total_marks' => (float) $assessment->total_marks,
                    'course_code' => $course->course_code,
                    'course_title' => $course->course_name,
                ],
                'questions' => $formattedQuestions,
                'learning_outcomes' => $formattedLos,
                'course_topics' => $topics,
                'previous_questions' => $formattedPrev,
                'weights' => $validated['weights'] ?? null,
                'difficulty_targets' => $validated['difficulty_targets'] ?? null,
                'custom_rules' => $validated['custom_rules'] ?? null,
            ];
        } else {
            // Direct payload mode
            $payload = [
                'course_id' => $validated['course_id'] ?? null,
                'questions' => $validated['questions'] ?? [],
                'learning_outcomes' => $validated['learning_outcomes'] ?? [],
                'course_topics' => $validated['course_topics'] ?? [],
                'previous_questions' => $validated['previous_questions'] ?? [],
                'weights' => $validated['weights'] ?? null,
                'difficulty_targets' => $validated['difficulty_targets'] ?? null,
                'custom_rules' => $validated['custom_rules'] ?? null,
            ];
        }

        try {
            $aiResult = $this->aiService->analyzeAssessment($payload);
            $this->validateUnifiedAiResponse($aiResult);

            // Persist to database if assessment exists
            if ($assessment) {
                $qualityAnalysis = $aiResult['quality_analysis'] ?? [];
                $recommendationsData = $aiResult['recommendations'] ?? [];
                $alignmentAnalysis = $aiResult['alignment_analysis'] ?? [];
                $similarityAnalysis = $aiResult['similarity_analysis'] ?? [];
                $components = $qualityAnalysis['components'] ?? [];

                DB::transaction(function () use (
                    $assessment,
                    $questions,
                    $prevQuestions,
                    $aiResult,
                    $qualityAnalysis,
                    $recommendationsData,
                    $alignmentAnalysis,
                    $similarityAnalysis,
                    $components
                ) {
                    // 1. Update/create AnalysisReport with versioning
                    $similarCount = ($similarityAnalysis['potential_duplicates_count'] ?? 0) + ($similarityAnalysis['highly_similar_count'] ?? 0);

                    $existingCompleted = AnalysisReport::where('assessment_id', $assessment->id)
                        ->where('analysis_status', 'completed')
                        ->orderByDesc('analysis_version')
                        ->first();

                    if ($existingCompleted) {
                        $nextVersion = $existingCompleted->analysis_version + 1;
                        AnalysisReport::where('assessment_id', $assessment->id)->update(['is_current' => false]);

                        $report = AnalysisReport::create([
                            'assessment_id' => $assessment->id,
                            'analysis_version' => $nextVersion,
                            'is_current' => true,
                            'overall_score' => (float) ($qualityAnalysis['overall_quality_score'] ?? 0.0),
                            'topic_coverage_score' => (float) ($components['topic_coverage'] ?? 0.0),
                            'learning_outcome_alignment_score' => (float) ($components['learning_outcome_coverage'] ?? ($alignmentAnalysis['overall_alignment_score'] ?? 0.0)),
                            'difficulty_balance_score' => (float) ($components['difficulty_balance'] ?? 0.0),
                            'cognitive_level_balance_score' => (float) ($components['cognitive_diversity'] ?? 0.0),
                            'similarity_score' => (float) ($similarityAnalysis['average_similarity_score'] ?? 0.0),
                            'similar_questions_count' => $similarCount,
                            'total_questions' => count($questions),
                            'analysis_status' => 'completed',
                            'processing_error' => null,
                            'findings' => [
                                'summary' => $aiResult['summary'] ?? [],
                                'quality' => $qualityAnalysis,
                                'quality_engine' => $qualityAnalysis,
                                'alignment' => $alignmentAnalysis,
                                'similarity' => $similarityAnalysis,
                            ],
                            'analyzed_at' => now(),
                        ]);
                    } else {
                        $report = AnalysisReport::updateOrCreate(
                            ['assessment_id' => $assessment->id, 'analysis_version' => 1],
                            [
                                'analysis_version' => 1,
                                'is_current' => true,
                                'overall_score' => (float) ($qualityAnalysis['overall_quality_score'] ?? 0.0),
                                'topic_coverage_score' => (float) ($components['topic_coverage'] ?? 0.0),
                                'learning_outcome_alignment_score' => (float) ($components['learning_outcome_coverage'] ?? ($alignmentAnalysis['overall_alignment_score'] ?? 0.0)),
                                'difficulty_balance_score' => (float) ($components['difficulty_balance'] ?? 0.0),
                                'cognitive_level_balance_score' => (float) ($components['cognitive_diversity'] ?? 0.0),
                                'similarity_score' => (float) ($similarityAnalysis['average_similarity_score'] ?? 0.0),
                                'similar_questions_count' => $similarCount,
                                'total_questions' => count($questions),
                                'analysis_status' => 'completed',
                                'processing_error' => null,
                                'findings' => [
                                    'summary' => $aiResult['summary'] ?? [],
                                    'quality' => $qualityAnalysis,
                                    'quality_engine' => $qualityAnalysis,
                                    'alignment' => $alignmentAnalysis,
                                    'similarity' => $similarityAnalysis,
                                ],
                                'analyzed_at' => now(),
                            ]
                        );
                    }

                    // 2. Update question AI fields (preserving faculty manual fields)
                    $qAnalysisList = $aiResult['questions_analysis']['questions'] ?? [];
                    $qAnalysisMap = [];
                    foreach ($qAnalysisList as $qa) {
                        $qNum = $qa['number'] ?? $qa['question_number'] ?? null;
                        if ($qNum) {
                            $qAnalysisMap[$qNum] = $qa;
                        }
                    }

                    foreach ($questions as $q) {
                        $qa = $qAnalysisMap[$q->question_number] ?? null;
                        if ($qa) {
                            $q->ai_cognitive_level = $qa['cognitive_level']['level'] ?? $q->ai_cognitive_level;
                            $q->ai_difficulty_level = $qa['difficulty']['level'] ?? $q->ai_difficulty_level;
                            $q->ai_question_type = $qa['classification']['type'] ?? ($qa['classification']['question_type'] ?? $q->ai_question_type);
                            $q->ai_topics = array_map(function ($t) {
                                return is_array($t) ? ($t['name'] ?? '') : (string) $t;
                            }, $qa['topics'] ?? []);
                            $q->ai_analysis_status = 'completed';
                            $q->ai_analyzed_at = now();
                            $q->save();
                        }
                    }

                    // 3. Persist similarity matches if available
                    QuestionSimilarityMatch::where('analysis_report_id', $report->id)->delete();
                    if (!empty($similarityAnalysis['matches'])) {
                        $qByNumber = $questions->keyBy('question_number');
                        $pqById = $prevQuestions->keyBy('id');

                        foreach ($similarityAnalysis['matches'] as $matchGroup) {
                            $cNum = $matchGroup['current_question_number'] ?? null;
                            $cQuestion = $qByNumber->get($cNum);

                            if ($cQuestion && !empty($matchGroup['matches'])) {
                                foreach ($matchGroup['matches'] as $m) {
                                    $pId = $m['previous_question_id'] ?? null;
                                    if ($pId && $pqById->has($pId)) {
                                        QuestionSimilarityMatch::create([
                                            'analysis_report_id' => $report->id,
                                            'current_question_id' => $cQuestion->id,
                                            'previous_question_id' => $pId,
                                            'similarity_score' => $m['similarity_score'] ?? 0.0,
                                            'similarity_status' => $m['similarity_status'] ?? 'NOT_SIMILAR',
                                            'reasoning' => $matchGroup['reasoning'] ?? null,
                                        ]);
                                    }
                                }
                            }
                        }
                    }

                    // 4. Persist Learning Outcome Alignment records
                    QuestionLearningOutcomeAlignment::where('analysis_report_id', $report->id)->delete();
                    $qaList = $alignmentAnalysis['question_alignment'] ?? [];
                    if (!empty($qaList) && $assessment->course->learningOutcomes->isNotEmpty()) {
                        $loById = $assessment->course->learningOutcomes->keyBy('id');
                        $qById = $questions->keyBy('id');

                        foreach ($qaList as $qaItem) {
                            $qId = $qaItem['question_id'] ?? null;
                            $matchedLo = $qaItem['matched_learning_outcome'] ?? null;
                            $loId = $matchedLo['id'] ?? null;

                            if ($qId && $loId && $qById->has($qId) && $loById->has($loId)) {
                                QuestionLearningOutcomeAlignment::create([
                                    'analysis_report_id' => $report->id,
                                    'question_id' => $qId,
                                    'learning_outcome_id' => $loId,
                                    'similarity_score' => $qaItem['alignment_score'] ?? ($matchedLo['similarity_score'] ?? 0.0),
                                    'alignment' => $qaItem['alignment_status'] ?? 'NOT_ALIGNED',
                                    'reasoning' => $qaItem['reasoning'] ?? null,
                                ]);
                            }
                        }
                    }

                    // 5. Persist recommendations (preserving faculty manual decision status across versions)
                    $newRecList = $recommendationsData['recommendations'] ?? [];
                    if (!empty($newRecList)) {
                        $existingRecs = Recommendation::whereHas('analysisReport', function ($q) use ($assessment) {
                            $q->where('assessment_id', $assessment->id);
                        })->get()->keyBy('title');
                        $persistedIds = [];

                        foreach ($newRecList as $rec) {
                            $title = $rec['problem'] ?? 'Assessment Recommendation';
                            $existing = $existingRecs->get($title);

                            $status = $existing ? $existing->status : 'pending';
                            $facultyNotes = $existing ? $existing->faculty_notes : null;

                            $saved = Recommendation::updateOrCreate(
                                [
                                    'analysis_report_id' => $report->id,
                                    'title' => $title,
                                ],
                                [
                                    'category' => $rec['category'] ?? 'general',
                                    'problem' => $rec['problem'] ?? $title,
                                    'description' => $rec['recommendation'] ?? '',
                                    'explanation' => $rec['explanation'] ?? '',
                                    'recommendation' => $rec['recommendation'] ?? '',
                                    'evidence' => $rec['evidence'] ?? null,
                                    'source_metric' => $rec['source_metric'] ?? '',
                                    'priority' => strtolower($rec['priority'] ?? 'medium'),
                                    'status' => $status,
                                    'faculty_notes' => $facultyNotes,
                                ]
                            );

                            $persistedIds[] = $saved->id;
                        }

                        // Clean up stale pending recommendations
                        Recommendation::where('analysis_report_id', $report->id)
                            ->where('status', 'pending')
                            ->whereNotIn('id', $persistedIds)
                            ->delete();
                    }
                });
            }

            if ($assessment) {
                Cache::forget("user:{$user->id}:assessment:{$assessment->id}:analysis");

                $this->auditLogService->log(
                    'AI_ANALYSIS_COMPLETED',
                    $assessment,
                    $assessment->id,
                    [
                        'assessment_title' => $assessment->title,
                        'overall_score' => $aiResult['quality_analysis']['overall_quality_score'] ?? null,
                        'questions_analyzed' => count($questions),
                    ],
                    $user
                );
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Assessment analyzed successfully.',
                'data' => $aiResult,
            ]);
        } catch (Exception $e) {
            Log::error('Unified assessment analysis failed: ' . $e->getMessage());

            if ($assessment) {
                AnalysisReport::updateOrCreate(
                    ['assessment_id' => $assessment->id],
                    [
                        'analysis_status' => 'failed',
                        'processing_error' => $e->getMessage(),
                    ]
                );
            }

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $this->determineErrorStatus($e));
        }
    }

    /**
     * Route-model bound endpoint for analyzing a specific assessment.
     * Supports both async execution (when ?async=1 is passed) and synchronous execution.
     */
    public function analyzeAssessmentByRoute(Request $request, Assessment $assessment): JsonResponse
    {
        if ($request->boolean('async')) {
            $user = $request->user();

            // Authorization
            if (!$user->can('analyze', $assessment)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthorized access to assessment.',
                ], 403);
            }

            // Validation: must have questions
            if ($assessment->questions()->count() === 0) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Assessment has no questions to analyze. Please add questions before running analysis.',
                ], 422);
            }

            // Prevent duplicate simultaneous analysis (5-minute window)
            $activeReport = $assessment->latestAnalysisReport;
            if ($activeReport
                && $activeReport->analysis_status === 'processing'
                && $activeReport->updated_at
                && $activeReport->updated_at->diffInMinutes(now()) < 5
            ) {
                return response()->json([
                    'status'      => 'processing',
                    'message'     => 'Analysis is already processing for this assessment.',
                    'analysis_id' => $activeReport->id,
                ], 202);
            }

            // Create/update report row so frontend can poll status immediately
            $report = AnalysisReport::updateOrCreate(
                ['assessment_id' => $assessment->id],
                ['analysis_status' => 'processing', 'processing_error' => null]
            );

            // Dispatch the job to the queue
            AnalyzeAssessmentJob::dispatch($assessment, $user->id);

            $this->auditLogService->log('AI_ANALYSIS_QUEUED', $assessment, $assessment->id, [
                'assessment_title' => $assessment->title,
                'queued_by'        => $user->id,
            ], $user);

            return response()->json([
                'status'      => 'processing',
                'message'     => 'Assessment analysis has been queued and will complete shortly. Poll the status endpoint for updates.',
                'analysis_id' => $report->id,
            ], 202);
        }

        $request->merge(['assessment_id' => $assessment->id]);
        return $this->analyzeAssessment($request);
    }

    /**
     * Alias for batch question analysis.
     */
    public function questionAnalysis(Request $request): JsonResponse
    {
        return $this->analyzeQuestions($request);
    }

    /**
     * Alias for semantic similarity analysis.
     */
    public function similarityAnalysis(Request $request): JsonResponse
    {
        return $this->analyzeSimilarity($request);
    }

    /**
     * Retrieve complete assessment analysis dashboard data from database.
     * Consolidates report, metrics, quality dimensions, LO alignment, similarity matches,
     * findings, and recommendations for high-performance single-request dashboard rendering.
     */
    public function getAssessmentAnalysis(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        // Check ownership
        if (!$user->can('viewAnalysis', $assessment)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access to assessment analysis.',
            ], 403);
        }

        // Serve from cache for completed analyses (5-minute TTL, user-scoped key)
        $cacheKey = "user:{$user->id}:assessment:{$assessment->id}:analysis";
        $cached   = Cache::get($cacheKey);
        if ($cached !== null && ($cached['data']['analysis_status'] ?? '') === 'completed') {
            return response()->json($cached);
        }

        $assessment->load([
            'course.learningOutcomes',
            'questions.learningOutcome',
            'questionPaper',
            'latestAnalysisReport.recommendations',
            'latestAnalysisReport.similarityMatches.previousQuestion',
            'latestAnalysisReport.learningOutcomeAlignments.learningOutcome',
        ])->loadCount('questions');

        $report = $assessment->latestAnalysisReport;

        // If no analysis report exists yet, return empty state data
        if (!$report) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'assessment' => [
                        'id' => $assessment->id,
                        'title' => $assessment->title,
                        'type' => $assessment->type,
                        'total_marks' => (float) $assessment->total_marks,
                        'total_questions' => $assessment->questions_count,
                        'duration_minutes' => $assessment->duration_minutes,
                        'assessment_date' => $assessment->assessment_date?->toDateString(),
                        'course_id' => $assessment->course->id,
                        'course_code' => $assessment->course->course_code,
                        'course_name' => $assessment->course->course_name,
                    ],
                    'report' => null,
                    'analysis_status' => 'not_analyzed',
                    'quality_analysis' => null,
                    'alignment_analysis' => null,
                    'similarity_analysis' => null,
                    'recommendations' => [],
                    'recommendation_summary' => [
                        'total_recommendations' => 0,
                        'high_priority_count' => 0,
                        'medium_priority_count' => 0,
                        'low_priority_count' => 0,
                        'accepted_count' => 0,
                        'dismissed_count' => 0,
                        'pending_count' => 0,
                    ],
                    'findings' => [],
                    'questions' => $assessment->questions,
                ],
            ]);
        }

        $findingsPayload = $report->findings ?? [];
        $qualityAnalysis = $findingsPayload['quality'] ?? $findingsPayload['quality_engine'] ?? null;
        $alignmentAnalysis = $findingsPayload['alignment'] ?? null;
        $similarityAnalysis = $findingsPayload['similarity'] ?? null;
        $summary = $findingsPayload['summary'] ?? [];

        // Build recommendations list with proper sorting
        $recommendations = $report->recommendations()
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('id', 'desc')
            ->get();

        $recommendationSummary = [
            'total_recommendations' => $recommendations->count(),
            'high_priority_count' => $recommendations->where('priority', 'high')->count(),
            'medium_priority_count' => $recommendations->where('priority', 'medium')->count(),
            'low_priority_count' => $recommendations->where('priority', 'low')->count(),
            'accepted_count' => $recommendations->where('status', 'accepted')->count(),
            'dismissed_count' => $recommendations->where('status', 'dismissed')->count(),
            'reviewed_count' => $recommendations->where('status', 'reviewed')->count(),
            'pending_count' => $recommendations->where('status', 'pending')->count(),
        ];

        // Format findings
        $rawFindings = [];
        if (!empty($qualityAnalysis['findings'])) {
            $rawFindings = array_merge($rawFindings, $qualityAnalysis['findings']);
        }
        if (!empty($alignmentAnalysis['findings'])) {
            $rawFindings = array_merge($rawFindings, $alignmentAnalysis['findings']);
        }
        if (!empty($similarityAnalysis['findings'])) {
            $rawFindings = array_merge($rawFindings, $similarityAnalysis['findings']);
        }
        $uniqueFindings = array_values(array_unique($rawFindings));

        // Format LO alignments mapping
        $loAlignments = $report->learningOutcomeAlignments->map(function ($loa) {
            return [
                'id' => $loa->id,
                'question_id' => $loa->question_id,
                'learning_outcome_id' => $loa->learning_outcome_id,
                'learning_outcome_code' => $loa->learningOutcome?->code,
                'learning_outcome_description' => $loa->learningOutcome?->description,
                'similarity_score' => (float) $loa->similarity_score,
                'alignment' => $loa->alignment,
                'reasoning' => $loa->reasoning,
            ];
        });

        // Format similarity matches
        $similarityMatches = $report->similarityMatches->map(function ($sm) {
            return [
                'id' => $sm->id,
                'current_question_id' => $sm->current_question_id,
                'previous_question_id' => $sm->previous_question_id,
                'previous_question_text' => $sm->previousQuestion?->question_text,
                'previous_assessment_title' => $sm->previousQuestion?->source_assessment,
                'previous_year' => $sm->previousQuestion?->source_year,
                'similarity_score' => (float) $sm->similarity_score,
                'similarity_status' => $sm->similarity_status,
                'reasoning' => $sm->reasoning,
            ];
        });

        // Rating calculation helper if not in qualityAnalysis
        $overallScore = $report->overall_score !== null ? (float) $report->overall_score : null;
        $rating = 'UNAVAILABLE';
        if ($overallScore !== null) {
            if ($overallScore >= 90) {
                $rating = 'EXCELLENT';
            } elseif ($overallScore >= 80) {
                $rating = 'GOOD';
            } elseif ($overallScore >= 70) {
                $rating = 'FAIR';
            } elseif ($overallScore >= 60) {
                $rating = 'NEEDS_REVIEW';
            } else {
                $rating = 'REQUIRES_ATTENTION';
            }
        }

        $responseData = [
            'status' => 'success',
            'data'   => [
                'assessment' => [
                    'id'               => $assessment->id,
                    'title'            => $assessment->title,
                    'type'             => $assessment->type,
                    'total_marks'      => (float) $assessment->total_marks,
                    'total_questions'  => $assessment->questions_count,
                    'duration_minutes' => $assessment->duration_minutes,
                    'assessment_date'  => $assessment->assessment_date?->toDateString(),
                    'course_id'        => $assessment->course->id,
                    'course_code'      => $assessment->course->course_code,
                    'course_name'      => $assessment->course->course_name,
                ],
                'report' => [
                    'id'                               => $report->id,
                    'overall_score'                    => $overallScore,
                    'rating'                           => $qualityAnalysis['rating'] ?? $rating,
                    'topic_coverage_score'             => $report->topic_coverage_score !== null ? (float) $report->topic_coverage_score : null,
                    'learning_outcome_alignment_score' => $report->learning_outcome_alignment_score !== null ? (float) $report->learning_outcome_alignment_score : null,
                    'difficulty_balance_score'         => $report->difficulty_balance_score !== null ? (float) $report->difficulty_balance_score : null,
                    'cognitive_level_balance_score'    => $report->cognitive_level_balance_score !== null ? (float) $report->cognitive_level_balance_score : null,
                    'similarity_score'                 => $report->similarity_score !== null ? (float) $report->similarity_score : null,
                    'total_questions'                  => $report->total_questions,
                    'similar_questions_count'          => $report->similar_questions_count,
                    'analysis_status'                  => $report->analysis_status,
                    'processing_error'                 => $report->processing_error,
                    'analyzed_at'                      => $report->analyzed_at?->toIso8601String(),
                ],
                'analysis_status'           => $report->analysis_status,
                'quality_analysis'          => $qualityAnalysis,
                'alignment_analysis'        => $alignmentAnalysis,
                'similarity_analysis'       => $similarityAnalysis,
                'learning_outcome_alignments'=> $loAlignments,
                'similarity_matches'        => $similarityMatches,
                'recommendations'           => $recommendations,
                'recommendation_summary'    => $recommendationSummary,
                'findings'                  => $uniqueFindings,
                'summary'                   => $summary,
                'questions'                 => $assessment->questions,
            ],
        ];

        // Cache completed results for 5 minutes (user-scoped, never shared between users)
        if ($report->analysis_status === 'completed') {
            Cache::put($cacheKey, $responseData, 300);
        }

        return response()->json($responseData);
    }

    /**
     * Lightweight status polling endpoint for async analysis.
     * Returns only the current status fields without loading the full analysis payload.
     * Frontend polls this every 3 seconds after triggering analyzeAssessmentByRoute().
     */
    public function getAnalysisStatus(Request $request, Assessment $assessment): JsonResponse
    {
        $user = $request->user();

        if (!$user->can('viewAnalysis', $assessment)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized access to assessment.',
            ], 403);
        }

        $report = $assessment->latestAnalysisReport;

        if (!$report) {
            return response()->json([
                'analysis_status'  => 'not_analyzed',
                'analysis_id'      => null,
                'overall_score'    => null,
                'processing_error' => null,
                'analyzed_at'      => null,
                'updated_at'       => null,
            ]);
        }

        return response()->json([
            'analysis_status'  => $report->analysis_status,
            'analysis_id'      => $report->id,
            'overall_score'    => $report->overall_score !== null ? (float) $report->overall_score : null,
            'processing_error' => $report->processing_error,
            'analyzed_at'      => $report->analyzed_at?->toIso8601String(),
            'updated_at'       => $report->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Alias for learning outcome alignment analysis.
     */
    public function alignmentAnalysis(Request $request): JsonResponse
    {
        return $this->analyzeAlignment($request);
    }
}

