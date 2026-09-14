<?php

use App\Http\Controllers\Api\AcademicChatController;
use App\Http\Controllers\Api\AiEvaluationController;
use App\Http\Controllers\Api\AiExplainabilityController;
use App\Http\Controllers\Api\AcademicAnalyticsController;
use App\Http\Controllers\Api\AssessmentBlueprintController;
use App\Http\Controllers\Api\AssessmentVersionController;
use App\Http\Controllers\Api\InstitutionalReportController;
use App\Http\Controllers\Api\CollaborationCommentController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\QuestionGenerationController;
use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AiGradingController;
use App\Http\Controllers\Api\AnalysisHistoryController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionPaperController;
use App\Http\Controllers\Api\AssessmentReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoPoMappingController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LearningOutcomeController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\PreviousQuestionController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\RubricAlignmentController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\StudentAnswerController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentSubmissionController;
use Illuminate\Support\Facades\Route;

/**
 * Health check endpoints for FacultyLens (liveness + readiness; coarse, secret-free)
 */
Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/ready', [HealthController::class, 'ready']);

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
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:auth');
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

    // STEP 26: Student Answer Management (faculty-side; private academic records)
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students', [StudentController::class, 'store']);
    Route::get('/students/{student}', [StudentController::class, 'show']);
    Route::put('/students/{student}', [StudentController::class, 'update']);
    Route::delete('/students/{student}', [StudentController::class, 'destroy']);

    Route::get('/assessments/{assessment}/submissions', [StudentSubmissionController::class, 'index']);
    Route::get('/assessments/{assessment}/submissions/summary', [StudentSubmissionController::class, 'summary']);
    Route::post('/assessments/{assessment}/submissions', [StudentSubmissionController::class, 'store']);
    Route::post('/assessments/{assessment}/submissions/import', [StudentSubmissionController::class, 'import'])->middleware('throttle:uploads');
    Route::get('/submissions/{submission}', [StudentSubmissionController::class, 'show']);
    Route::patch('/submissions/{submission}/status', [StudentSubmissionController::class, 'updateStatus']);
    Route::delete('/submissions/{submission}', [StudentSubmissionController::class, 'destroy']);

    Route::post('/submissions/{submission}/answers', [StudentAnswerController::class, 'store'])->middleware('throttle:uploads');
    Route::put('/student-answers/{answer}', [StudentAnswerController::class, 'update'])->middleware('throttle:uploads');
    Route::post('/student-answers/{answer}', [StudentAnswerController::class, 'update'])->middleware('throttle:uploads'); // multipart updates (file replace)
    Route::delete('/student-answers/{answer}', [StudentAnswerController::class, 'destroy']);
    Route::get('/student-answers/{answer}/download', [StudentAnswerController::class, 'download']);

    // STEP 27: AI Grading Assistance (suggestion -> faculty review -> faculty final marks)
    Route::post('/student-answers/{answer}/ai-grade', [AiGradingController::class, 'request'])->middleware('throttle:ai-analysis');
    Route::get('/student-answers/{answer}/ai-grading', [AiGradingController::class, 'show']);
    Route::get('/student-answers/{answer}/ai-grading/history', [AiGradingController::class, 'history']);
    Route::post('/student-answers/{answer}/finalize-grade', [AiGradingController::class, 'finalizeGrade']);
    Route::put('/student-answers/{answer}/final-grade', [AiGradingController::class, 'finalizeGrade']);
    Route::post('/ai-grading/{result}/regenerate', [AiGradingController::class, 'regenerate'])->middleware('throttle:ai-analysis');
    Route::post('/ai-grading/{result}/reject', [AiGradingController::class, 'reject']);

    // STEP 28: Answer <-> Rubric Alignment (coverage evidence; never a grade)
    Route::post('/student-answers/{answer}/rubric-alignment', [RubricAlignmentController::class, 'request'])->middleware('throttle:ai-analysis');
    Route::get('/student-answers/{answer}/rubric-alignment', [RubricAlignmentController::class, 'show']);
    Route::get('/student-answers/{answer}/rubric-alignment/history', [RubricAlignmentController::class, 'history']);
    Route::post('/rubric-alignments/{alignment}/regenerate', [RubricAlignmentController::class, 'regenerate'])->middleware('throttle:ai-analysis');
    Route::post('/rubric-alignments/{alignment}/review', [RubricAlignmentController::class, 'review']);

    // STEP 30: Student Performance / Gap Analysis (finalized faculty marks only; review signals, not decisions)
    Route::get('/assessments/{assessment}/performance', [PerformanceController::class, 'show']);
    Route::post('/assessments/{assessment}/performance/analyze', [PerformanceController::class, 'analyze']);
    Route::get('/assessments/{assessment}/performance/questions', [PerformanceController::class, 'questions']);
    Route::get('/assessments/{assessment}/performance/topics', [PerformanceController::class, 'topics']);
    Route::get('/assessments/{assessment}/performance/learning-outcomes', [PerformanceController::class, 'learningOutcomes']);
    Route::get('/assessments/{assessment}/performance/history', [PerformanceController::class, 'history']);
    Route::get('/students/{student}/assessments/{assessment}/performance', [PerformanceController::class, 'student']);

    // STEP 31: CO/PO Mapping Validator (review signals; never an accreditation decision)
    Route::get('/programs', [ProgramController::class, 'index']);
    Route::post('/programs', [ProgramController::class, 'store']);
    Route::get('/programs/{program}', [ProgramController::class, 'show']);
    Route::put('/programs/{program}', [ProgramController::class, 'update']);
    Route::delete('/programs/{program}', [ProgramController::class, 'destroy']);
    Route::get('/programs/{program}/outcomes', [ProgramController::class, 'outcomes']);
    Route::post('/programs/{program}/outcomes', [ProgramController::class, 'storeOutcome']);
    Route::put('/program-outcomes/{programOutcome}', [ProgramController::class, 'updateOutcome']);
    Route::delete('/program-outcomes/{programOutcome}', [ProgramController::class, 'destroyOutcome']);

    Route::get('/courses/{course}/co-po-mapping', [CoPoMappingController::class, 'show']);
    Route::post('/courses/{course}/co-po-mapping/analyze', [CoPoMappingController::class, 'analyze']);
    Route::get('/courses/{course}/co-po-mapping/matrix', [CoPoMappingController::class, 'matrix']);
    Route::get('/courses/{course}/co-po-mapping/findings', [CoPoMappingController::class, 'findings']);
    Route::get('/courses/{course}/co-po-mapping/co-performance', [CoPoMappingController::class, 'coPerformance']);
    Route::get('/courses/{course}/co-po-mapping/po-evidence', [CoPoMappingController::class, 'poEvidence']);
    Route::get('/courses/{course}/co-po-mapping/question-mappings', [CoPoMappingController::class, 'questionMappings']);
    Route::post('/courses/{course}/co-po-mappings', [CoPoMappingController::class, 'storeMapping']);
    Route::put('/co-po-mappings/{mapping}', [CoPoMappingController::class, 'updateMapping']);
    Route::delete('/co-po-mappings/{mapping}', [CoPoMappingController::class, 'destroyMapping']);
    Route::post('/questions/{question}/co-mappings/confirm', [CoPoMappingController::class, 'confirmQuestionMapping']);
    Route::post('/questions/{question}/co-mappings/reject', [CoPoMappingController::class, 'rejectQuestionMapping']);

    // STEP 32: AI Chat with Academic Documents (RAG)
    Route::prefix('academic-chat')->group(function () {
        Route::get('/sessions', [AcademicChatController::class, 'index']);
        Route::post('/sessions', [AcademicChatController::class, 'store']);
        Route::get('/sessions/{session}', [AcademicChatController::class, 'show']);
        Route::delete('/sessions/{session}', [AcademicChatController::class, 'destroy']);
        Route::post('/sessions/{session}/messages', [AcademicChatController::class, 'sendMessage'])->middleware('throttle:academic-chat');
    });

    // STEP 33: Constrained Question Generator (drafts only; approval + explicit add-to-assessment required)
    Route::get('/question-generation', [QuestionGenerationController::class, 'index']);
    Route::post('/question-generation', [QuestionGenerationController::class, 'store'])->middleware('throttle:question-generation');
    Route::get('/question-generation/{generation}', [QuestionGenerationController::class, 'show']);
    Route::get('/question-generation/{generation}/questions', [QuestionGenerationController::class, 'questions']);
    Route::post('/question-generation/{generation}/regenerate', [QuestionGenerationController::class, 'regenerate'])->middleware('throttle:question-generation');
    Route::put('/generated-questions/{generatedQuestion}', [QuestionGenerationController::class, 'update']);
    Route::post('/generated-questions/{generatedQuestion}/approve', [QuestionGenerationController::class, 'approve']);
    Route::post('/generated-questions/{generatedQuestion}/reject', [QuestionGenerationController::class, 'reject']);
    Route::post('/generated-questions/{generatedQuestion}/regenerate', [QuestionGenerationController::class, 'regenerateQuestion'])->middleware('throttle:question-generation');
    Route::post('/generated-questions/{generatedQuestion}/add-to-assessment', [QuestionGenerationController::class, 'addToAssessment']);

    // STEP 34: Faculty Collaboration (membership, invitations, comments, activity, notifications)
    Route::get('/courses/{course}/collaboration', [CollaborationController::class, 'show']);
    Route::get('/courses/{course}/collaboration/activity', [CollaborationController::class, 'activity']);
    Route::get('/courses/{course}/collaboration/members', [CollaborationController::class, 'members']);
    Route::delete('/courses/{course}/collaboration/invitations/{invitation}', [CollaborationController::class, 'revokeInvitation']);
    Route::get('/courses/{course}/collaborators', [CollaborationController::class, 'collaborators']);
    Route::post('/courses/{course}/collaborators/invite', [CollaborationController::class, 'invite'])->middleware('throttle:collaboration-invite');
    Route::patch('/courses/{course}/collaborators/{user}/role', [CollaborationController::class, 'changeRole']);
    Route::delete('/courses/{course}/collaborators/{user}', [CollaborationController::class, 'remove']);
    Route::get('/collaboration/summary', [CollaborationController::class, 'summary']);
    Route::get('/collaboration/invitations', [CollaborationController::class, 'myInvitations']);
    Route::post('/collaboration/invitations/{token}/accept', [CollaborationController::class, 'accept']);
    Route::post('/collaboration/invitations/{token}/decline', [CollaborationController::class, 'decline']);
    Route::post('/collaboration/my-invitations/{invitation}/accept', [CollaborationController::class, 'acceptById']);
    Route::post('/collaboration/my-invitations/{invitation}/decline', [CollaborationController::class, 'declineById']);

    Route::get('/courses/{course}/comments', [CollaborationCommentController::class, 'index']);
    Route::post('/courses/{course}/comments', [CollaborationCommentController::class, 'store'])->middleware('throttle:collaboration-comment');
    Route::get('/comments/{comment}', [CollaborationCommentController::class, 'show']);
    Route::put('/comments/{comment}', [CollaborationCommentController::class, 'update']);
    Route::delete('/comments/{comment}', [CollaborationCommentController::class, 'destroy']);
    Route::post('/comments/{comment}/resolve', [CollaborationCommentController::class, 'resolve']);
    Route::post('/comments/{comment}/reopen', [CollaborationCommentController::class, 'reopen']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // STEP 37: Assessment Blueprint (planning + validation layer; never publishes/finalizes the assessment)
    Route::get('/assessments/{assessment}/blueprint', [AssessmentBlueprintController::class, 'show']);
    Route::post('/assessments/{assessment}/blueprint', [AssessmentBlueprintController::class, 'store']);
    Route::put('/blueprints/{blueprint}', [AssessmentBlueprintController::class, 'update']);
    Route::delete('/blueprints/{blueprint}', [AssessmentBlueprintController::class, 'destroy']);
    Route::post('/blueprints/{blueprint}/validate', [AssessmentBlueprintController::class, 'validateBlueprint']);
    Route::post('/blueprints/{blueprint}/finalize', [AssessmentBlueprintController::class, 'finalize']);
    Route::get('/blueprints/{blueprint}/coverage', [AssessmentBlueprintController::class, 'coverage']);
    Route::get('/blueprints/{blueprint}/comparison', [AssessmentBlueprintController::class, 'comparison']);
    Route::post('/blueprints/{blueprint}/generate-questions', [AssessmentBlueprintController::class, 'generateQuestions'])->middleware('throttle:ai-analysis');
    Route::post('/blueprints/{blueprint}/validate-questions', [AssessmentBlueprintController::class, 'validateQuestions']);

    // STEP 38: Assessment Versioning (immutable historical snapshots; finalized versions are never edited in place)
    Route::get('/assessments/{assessment}/versions', [AssessmentVersionController::class, 'index']);
    Route::post('/assessments/{assessment}/versions', [AssessmentVersionController::class, 'store']);
    Route::get('/assessments/{assessment}/versions/{version}', [AssessmentVersionController::class, 'show']);
    Route::put('/assessment-versions/{version}', [AssessmentVersionController::class, 'update']);
    Route::post('/assessment-versions/{version}/submit-review', [AssessmentVersionController::class, 'submitReview']);
    Route::post('/assessment-versions/{version}/approve', [AssessmentVersionController::class, 'approve']);
    Route::post('/assessment-versions/{version}/finalize', [AssessmentVersionController::class, 'finalize']);
    Route::post('/assessment-versions/{version}/archive', [AssessmentVersionController::class, 'archive']);
    Route::post('/assessment-versions/{version}/restore', [AssessmentVersionController::class, 'restore']);
    Route::post('/assessment-versions/{version}/validate', [AssessmentVersionController::class, 'validateVersion']);
    Route::get('/assessment-versions/{version}/compare/{otherVersion}', [AssessmentVersionController::class, 'compare']);
    Route::get('/assessment-versions/{version}/analysis', [AssessmentVersionController::class, 'analysis']);
    Route::get('/assessment-versions/{version}/blueprint', [AssessmentVersionController::class, 'blueprint']);

    // STEP 36: Academic Analytics Dashboard (read-only aggregates; authorization enforced in the scope service)
    Route::prefix('analytics')->group(function () {
        Route::get('/overview', [AcademicAnalyticsController::class, 'overview']);
        Route::get('/filters', [AcademicAnalyticsController::class, 'filters']);
        Route::get('/compare', [AcademicAnalyticsController::class, 'compare']);
        Route::get('/export', [AcademicAnalyticsController::class, 'export']);
        Route::get('/courses/{course}', [AcademicAnalyticsController::class, 'course']);
        Route::get('/courses/{course}/{section}', [AcademicAnalyticsController::class, 'courseSection'])->whereIn('section', ['assessments', 'performance', 'outcomes', 'ai', 'similarity', 'history']);
    });

    // STEP 45: AI Explainability & Transparency (explain / review / override any AI result by type + id)
    Route::prefix('ai-results/{type}/{id}')->whereNumber('id')->group(function () {
        Route::get('/explanation', [AiExplainabilityController::class, 'explanation']);
        Route::get('/reviews', [AiExplainabilityController::class, 'reviews']);
        Route::post('/review', [AiExplainabilityController::class, 'review']);
        Route::post('/override', [AiExplainabilityController::class, 'override']);
        Route::post('/events', [AiExplainabilityController::class, 'event']);
    });

    // STEP 35: AI Evaluation & Model Performance (evaluation is separate from production AI)
    Route::prefix('ai/evaluation')->group(function () {
        Route::get('/', [AiEvaluationController::class, 'index']);
        Route::get('/models', [AiEvaluationController::class, 'models']);
        Route::get('/prompts', [AiEvaluationController::class, 'prompts']);
        Route::get('/datasets', [AiEvaluationController::class, 'datasets']);
        Route::post('/datasets', [AiEvaluationController::class, 'storeDataset']);
        Route::get('/datasets/{dataset}', [AiEvaluationController::class, 'showDataset']);
        Route::put('/datasets/{dataset}', [AiEvaluationController::class, 'updateDataset']);
        Route::delete('/datasets/{dataset}', [AiEvaluationController::class, 'destroyDataset']);
        Route::delete('/datasets/{dataset}/examples/{example}', [AiEvaluationController::class, 'destroyExample']);
        Route::post('/datasets/{dataset}/validate', [AiEvaluationController::class, 'validateDataset']);
        Route::post('/datasets/{dataset}/run', [AiEvaluationController::class, 'run'])->middleware('throttle:ai-analysis');
        Route::get('/runs', [AiEvaluationController::class, 'runs']);
        Route::get('/compare', [AiEvaluationController::class, 'compare']);
        Route::get('/runs/{run}', [AiEvaluationController::class, 'showRun']);
        Route::get('/runs/{run}/metrics', [AiEvaluationController::class, 'metrics']);
        Route::get('/runs/{run}/errors', [AiEvaluationController::class, 'errors']);
        Route::get('/runs/{run}/report', [AiEvaluationController::class, 'reportJson']);
        Route::get('/runs/{run}/export', [AiEvaluationController::class, 'export']);
        Route::post('/runs/{run}/cancel', [AiEvaluationController::class, 'cancel']);
        Route::post('/ratings', [AiEvaluationController::class, 'rate']);
    });

    // STEP 39: Institutional Export & Reporting (authorization + scoping resolved server-side; private downloads)
    Route::prefix('reports')->group(function () {
        Route::get('/types', [InstitutionalReportController::class, 'types']);
        Route::get('/filters', [InstitutionalReportController::class, 'filters']);
        Route::get('/', [InstitutionalReportController::class, 'index']);
        Route::post('/preview', [InstitutionalReportController::class, 'preview'])->middleware('throttle:ai-analysis');
        Route::post('/', [InstitutionalReportController::class, 'store'])->middleware('throttle:ai-analysis');
        Route::get('/{report}', [InstitutionalReportController::class, 'show'])->whereNumber('report');
        Route::get('/{report}/download', [InstitutionalReportController::class, 'download'])->whereNumber('report');
        Route::delete('/{report}', [InstitutionalReportController::class, 'destroy'])->whereNumber('report');
    });
});

// STEP 34: minimal public invitation preview (course code/name, inviter, role, expiry — no course content)
Route::get('/collaboration/invitations/{token}', [CollaborationController::class, 'preview'])->middleware('throttle:auth');

// Public Shared Report Endpoints (STEP 18)
Route::get('/shared/reports/{token}', [AssessmentReportController::class, 'viewShared']);
Route::get('/shared/reports/{token}/download', [AssessmentReportController::class, 'downloadShared']);

