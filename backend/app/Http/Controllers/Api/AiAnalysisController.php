<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}

