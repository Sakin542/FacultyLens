<?php

use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionPaperController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LearningOutcomeController;
use App\Http\Controllers\Api\PreviousQuestionController;
use Illuminate\Support\Facades\Route;

/**
 * Health check endpoint for FacultyLens
 */
Route::get('/health', [HealthController::class, 'check']);

/**
 * Faculty Authentication Endpoints
 */
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

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
    Route::post('/assessments/{assessment}/question-paper', [AssessmentQuestionPaperController::class, 'store']);
    Route::delete('/assessments/{assessment}/question-paper', [AssessmentQuestionPaperController::class, 'destroy']);

    // Previous Questions / Question Bank
    Route::get('/courses/{course}/previous-questions', [PreviousQuestionController::class, 'index']);
    Route::post('/courses/{course}/previous-questions', [PreviousQuestionController::class, 'store']);
    Route::get('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'show']);
    Route::put('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'update']);
    Route::delete('/previous-questions/{previousQuestion}', [PreviousQuestionController::class, 'destroy']);

    // Document Processing & Extraction (STEP 08)
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);
    Route::post('/documents/{document}/reprocess', [DocumentController::class, 'reprocess']);
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);

    // Hugging Face AI Service Integration (STEP 09, STEP 10, STEP 11)
    Route::prefix('ai')->group(function () {
        Route::get('/health', [AiAnalysisController::class, 'health']);
        Route::post('/analyze', [AiAnalysisController::class, 'analyze']);
        Route::post('/analyze-question', [AiAnalysisController::class, 'analyzeQuestion']);
        Route::post('/analyze-questions', [AiAnalysisController::class, 'analyzeQuestions']);
        Route::post('/analyze-alignment', [AiAnalysisController::class, 'analyzeAlignment']);
        Route::post('/assessments/{assessment}/analyze-questions', [AiAnalysisController::class, 'analyzeAssessmentQuestions']);
        Route::post('/assessments/{assessment}/analyze-alignment', [AiAnalysisController::class, 'analyzeAssessmentAlignment']);
    });
});
