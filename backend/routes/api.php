<?php

use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AnalysisHistoryController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionPaperController;
use App\Http\Controllers\Api\AssessmentReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LearningOutcomeController;
use App\Http\Controllers\Api\PreviousQuestionController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\RubricController;
use Illuminate\Support\Facades\Route;

/**
 * Health check endpoint for FacultyLens
 */
Route::get('/health', [HealthController::class, 'check']);

/**
 * Faculty Authentication Endpoints
 */
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::patch('/user', [AuthController::class, 'updateProfile']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->patch('/users/me', [AuthController::class, 'updateProfile']);

/**
 * Protected Faculty, Course & Assessment Management Endpoints
 */
Route::middleware('auth:sanctum')->group(function () {
    // Course CRUD
    Route::apiResource('courses', CourseController::class);

    // Learning Outcomes
    Route::get('/courses/{course}/learning-outcomes', [LearningOutcomeController::class, 'index']);
    Route::post('/courses/{course}/learning-outcomes', [LearningOutcomeController::class, 'store']);
    Route::put('/learning-outcomes/{learningOutcome}', [LearningOutcomeController::class, 'update']);
    Route::delete('/learning-outcomes/{learningOutcome}', [LearningOutcomeController::class, 'destroy']);

    // Course Materials
    Route::get('/courses/{course}/materials', [CourseMaterialController::class, 'index']);
    Route::post('/courses/{course}/materials', [CourseMaterialController::class, 'store']);
    Route::get('/materials/{material}', [CourseMaterialController::class, 'show']);
    Route::delete('/materials/{material}', [CourseMaterialController::class, 'destroy']);

    // Assessment Management
    Route::get('/assessments/history', [AssessmentController::class, 'history']);
    Route::get('/assessments', [AssessmentController::class, 'index']);
    Route::get('/courses/{course}/assessments', [AssessmentController::class, 'index']);
    Route::post('/courses/{course}/assessments', [AssessmentController::class, 'store']);
    Route::get('/assessments/{assessment}', [AssessmentController::class, 'show']);
    Route::put('/assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy']);

    // Assessment Question Paper File Management
    Route::get('/assessments/{assessment}/question-paper', [AssessmentQuestionPaperController::class, 'show']);
    Route::post('/assessments/{assessment}/question-paper', [AssessmentQuestionPaperController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('/assessments/{assessment}/question-paper', [AssessmentQuestionPaperController::class, 'destroy']);

    // Previous Questions / Question Bank
    Route::get('/courses/{course}/previous-questions', [PreviousQuestionController::class, 'index']);
    Route::post('/courses/{course}/previous-questions', [PreviousQuestionController::class, 'store']);
    Route::get('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'show']);
    Route::put('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'update']);
    Route::delete('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'destroy']);

    // Document Processing & Extraction (STEP 08)
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:uploads');
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    // Hugging Face AI Service Integration (STEP 09, STEP 10, STEP 11, STEP 12)
    Route::prefix('ai')->middleware('throttle:ai-analysis')->group(function () {
        Route::get('/health', [AiAnalysisController::class, 'health']);
        Route::post('/analyze', [AiAnalysisController::class, 'analyze']);
        Route::post('/analyze-document', [AiAnalysisController::class, 'analyzeDocument']);
        Route::post('/analyze-question', [AiAnalysisController::class, 'analyzeQuestion']);
        Route::post('/analyze-questions', [AiAnalysisController::class, 'analyzeQuestions']);
        Route::post('/analyze-alignment', [AiAnalysisController::class, 'analyzeAlignment']);
        Route::post('/analyze-similarity', [AiAnalysisController::class, 'analyzeSimilarity']);
        Route::post('/analyze-assessment-quality', [AiAnalysisController::class, 'analyzeQuality']);
        Route::post('/assessments/{assessment}/analyze-questions', [AiAnalysisController::class, 'analyzeAssessmentQuestions']);
        Route::post('/assessments/{assessment}/analyze-alignment', [AiAnalysisController::class, 'analyzeAssessmentAlignment']);
        Route::post('/assessments/{assessment}/analyze-similarity', [AiAnalysisController::class, 'analyzeAssessmentSimilarity']);
        Route::post('/assessments/{assessment}/analyze-quality', [AiAnalysisController::class, 'analyzeAssessmentQuality']);

        // AI Recommendation Engine (STEP 14)
        Route::post('/generate-recommendations', [AiAnalysisController::class, 'generateRecommendations']);
        Route::post('/assessments/{assessment}/generate-recommendations', [AiAnalysisController::class, 'generateAssessmentRecommendations']);
        Route::get('/assessments/{assessment}/recommendations', [AiAnalysisController::class, 'getAssessmentRecommendations']);
        Route::patch('/recommendations/{recommendation}/status', [AiAnalysisController::class, 'updateRecommendationStatus']);

        // STEP 15 & 17 Unified AI Analysis API & Canonical Aliases
        Route::get('/assessments/{assessment}/analysis', [AiAnalysisController::class, 'getAssessmentAnalysis']);
        Route::get('/assessments/{assessment}/analysis-status', [AiAnalysisController::class, 'getAnalysisStatus']);
        Route::post('/analyze-assessment', [AiAnalysisController::class, 'analyzeAssessment']);
        Route::post('/assessments/{assessment}/analyze', [AiAnalysisController::class, 'analyzeAssessmentByRoute']);
        Route::post('/question-analysis', [AiAnalysisController::class, 'questionAnalysis']);
        Route::post('/similarity-analysis', [AiAnalysisController::class, 'similarityAnalysis']);
        Route::post('/alignment-analysis', [AiAnalysisController::class, 'alignmentAnalysis']);
    });

    // STEP 18: Assessment Report Generation & Export
    Route::get('/assessments/{assessment}/report', [AssessmentReportController::class, 'show']);
    Route::post('/assessments/{assessment}/report/generate', [AssessmentReportController::class, 'generate']);
    Route::get('/assessment-reports/{report}/download', [AssessmentReportController::class, 'download']);
    Route::post('/assessment-reports/{report}/share', [AssessmentReportController::class, 'share']);
    Route::post('/assessment-reports/{report}/revoke-share', [AssessmentReportController::class, 'revokeShare']);

    // STEP 19: Analysis History, Comparison & Trends
    Route::get('/analysis/history', [AnalysisHistoryController::class, 'index']);
    Route::get('/analysis/compare', [AnalysisHistoryController::class, 'compare']);
    Route::get('/analysis/trends', [AnalysisHistoryController::class, 'trend']);
    Route::get('/analysis/improvement-summary', [AnalysisHistoryController::class, 'improvementSummary']);
    Route::get('/analysis/{id}', [AnalysisHistoryController::class, 'show']);
    Route::get('/assessments/{assessment}/analysis-history', [AnalysisHistoryController::class, 'assessmentHistory']);

    // STEP 20: Faculty Feedback & Recommendation Decisions
    Route::post('/recommendations/{recommendation}/feedback', [RecommendationFeedbackController::class, 'submit']);
    Route::get('/recommendations/{recommendation}/feedback', [RecommendationFeedbackController::class, 'show']);
    Route::patch('/recommendations/{recommendation}/status', [RecommendationFeedbackController::class, 'updateStatus']);
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::get('/feedback/summary', [FeedbackController::class, 'summary']);
    Route::get('/ai/improvement-signals', [FeedbackController::class, 'improvementSignals']);

    // STEP 25: AI Rubric Generator (draft -> faculty review -> approve)
    Route::post('/questions/{question}/rubrics/generate', [RubricController::class, 'generate'])->middleware('throttle:ai-analysis');
    Route::get('/questions/{question}/rubrics', [RubricController::class, 'index']);
    Route::get('/rubrics/{rubric}', [RubricController::class, 'show']);
    Route::put('/rubrics/{rubric}', [RubricController::class, 'update']);
    Route::delete('/rubrics/{rubric}', [RubricController::class, 'destroy']);
    Route::post('/rubrics/{rubric}/approve', [RubricController::class, 'approve']);
    Route::post('/rubrics/{rubric}/regenerate', [RubricController::class, 'regenerate'])->middleware('throttle:ai-analysis');
});

// Public Shared Report Endpoints (STEP 18)
Route::get('/shared/reports/{token}', [AssessmentReportController::class, 'viewShared']);
Route::get('/shared/reports/{token}/download', [AssessmentReportController::class, 'downloadShared']);

