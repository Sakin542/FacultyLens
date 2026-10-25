<?php

use App\Http\Controllers\Api\AcademicChatController;
use App\Http\Controllers\Api\Admin\AdminAccessController;
use App\Http\Controllers\Api\Admin\AdminAiGovernanceController;
use App\Http\Controllers\Api\Admin\AdminAiUsageController;
use App\Http\Controllers\Api\Admin\AdminAlertController;
use App\Http\Controllers\Api\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AdminBackupController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminDataQualityController;
use App\Http\Controllers\Api\Admin\AdminEmailController;
use App\Http\Controllers\Api\Admin\AdminIncidentController;
use App\Http\Controllers\Api\Admin\AdminInstitutionController;
use App\Http\Controllers\Api\Admin\AdminMaintenanceController;
use App\Http\Controllers\Api\Admin\AdminOperationController;
use App\Http\Controllers\Api\Admin\AdminQueueController;
use App\Http\Controllers\Api\Admin\AdminSearchController;
use App\Http\Controllers\Api\Admin\AdminSecurityController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminSystemController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AssessmentIntegrityController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CourseDesignController;
use App\Http\Controllers\Api\GradingQueueController;
use App\Http\Controllers\Api\ResearchController;
use App\Http\Controllers\Api\PlanningController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\FeedbackCommentController;
use App\Http\Controllers\Api\PublicConfigController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\AiEvaluationController;
use App\Http\Controllers\Api\AiToolkitController;
use App\Http\Controllers\Api\AiExplainabilityController;
use App\Http\Controllers\Api\AcademicAnalyticsController;
use App\Http\Controllers\Api\AssessmentBlueprintController;
use App\Http\Controllers\Api\AssessmentVersionController;
use App\Http\Controllers\Api\InstitutionalReportController;
use App\Http\Controllers\Api\CollaborationCommentController;
use App\Http\Controllers\Api\CollaborationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\QuestionGenerationController;
use App\Http\Controllers\Api\AiAnalysisController;
use App\Http\Controllers\Api\AiGradingController;
use App\Http\Controllers\Api\AnalysisHistoryController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentQuestionPaperController;
use App\Http\Controllers\Api\AssessmentQuestionController;
use App\Http\Controllers\Api\AssessmentReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfilePictureController;
use App\Http\Controllers\Api\CoPoMappingController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseMaterialController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SchedulerTriggerController;
use App\Http\Controllers\Api\LearningOutcomeController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\PreviousQuestionController;
use App\Http\Controllers\Api\RecommendationFeedbackController;
use App\Http\Controllers\Api\RubricAlignmentController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\StudentAnswerController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentSubmissionController;
use Illuminate\Support\Facades\Route;

/**
 * Health check endpoints for FacultyLens (liveness + readiness; coarse, secret-free)
 */
Route::get('/health', [HealthController::class, 'check']);
Route::get('/health/ready', [HealthController::class, 'ready']);

// External cron (cron-job.org) runs the Laravel scheduler where no scheduler process exists; token-guarded, 404 otherwise
Route::post('/internal/scheduler/run', SchedulerTriggerController::class)->middleware('throttle:10,1');

/**
 * Faculty Authentication Endpoints
 */
Route::prefix('auth')->group(function () {
    // Anonymous-safe session probe (200 whether or not a session exists)
    Route::get('/session', [AuthController::class, 'session']);

    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('feature:registration');
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/two-factor/challenge', [AuthController::class, 'twoFactorChallenge']);
        Route::post('/google', [AuthController::class, 'googleLogin'])->middleware('feature:google_sign_in');
    });

    // Password recovery (Laravel password broker → queued FacultyLens reset e-mail). Generic responses; per-IP + per-address limits.
    Route::middleware('throttle:password-reset')->group(function () {
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('/reset-password', [PasswordResetController::class, 'reset']);
    });

    // Logout endpoint (safe to call whether or not session is still fully valid)
    Route::post('/logout', [AuthController::class, 'logout']);

    // Protected auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::patch('/user', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:auth');

        // Self-service two-factor authentication (TOTP + recovery codes)
        Route::prefix('two-factor')->middleware('throttle:auth')->group(function () {
            Route::get('/', [TwoFactorController::class, 'status'])->withoutMiddleware('throttle:auth');
            Route::post('/enable', [TwoFactorController::class, 'enable']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
            Route::delete('/', [TwoFactorController::class, 'disable']);
        });
    });
});

// Anonymous-safe client configuration (feature toggles, Google client id)
Route::get('/config', PublicConfigController::class);

/**
 * Admin panel: only the configured administrator account (role ADMIN + ADMIN_EMAIL) gets past `admin`.
 */
Route::post('/admin/auth/login', [AuthController::class, 'adminLogin'])->middleware('throttle:auth');
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/overview', [AdminDashboardController::class, 'overview']);
    Route::get('/search', AdminSearchController::class)->middleware('throttle:60,1');

    // People and access
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store'])->middleware('throttle:30,1');
    Route::post('/users/bulk', [AdminUserController::class, 'bulk'])->middleware('throttle:20,1');
    Route::post('/users/import/preview', [AdminUserController::class, 'importPreview'])->middleware('throttle:20,1');
    Route::post('/users/import', [AdminUserController::class, 'import'])->middleware('throttle:5,1');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->whereNumber('user');
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->whereNumber('user')->middleware('throttle:20,1');
    Route::post('/users/{user}/revoke-sessions', [AdminUserController::class, 'revokeSessions'])->whereNumber('user');
    Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->whereNumber('user')->middleware('throttle:password-reset');
    Route::delete('/users/{user}/two-factor', [AdminUserController::class, 'resetTwoFactor'])->whereNumber('user');
    Route::get('/access', [AdminAccessController::class, 'index']);
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
    Route::get('/audit-logs/facets', [AdminAuditLogController::class, 'facets']);
    Route::get('/security', [AdminSecurityController::class, 'index']);

    // Institution
    Route::get('/institution', [AdminInstitutionController::class, 'overview']);
    Route::get('/institution/courses', [AdminInstitutionController::class, 'courses']);

    // Operations
    Route::get('/system/health', [AdminSystemController::class, 'health']);
    Route::post('/system/health/check', [AdminSystemController::class, 'runCheck'])->middleware('throttle:12,1');
    Route::get('/system/environment', [AdminSystemController::class, 'environment']);
    Route::get('/system/schedule', [AdminSystemController::class, 'schedule']);
    Route::get('/queues', [AdminQueueController::class, 'summary']);
    Route::get('/queues/failed', [AdminQueueController::class, 'failed']);
    Route::post('/queues/failed/{uuid}/retry', [AdminQueueController::class, 'retry'])->whereUuid('uuid')->middleware('throttle:30,1');
    Route::delete('/queues/failed/{uuid}', [AdminQueueController::class, 'forget'])->whereUuid('uuid');
    Route::get('/email', [AdminEmailController::class, 'summary']);
    Route::get('/email/deliveries', [AdminEmailController::class, 'deliveries']);
    Route::get('/alerts', [AdminAlertController::class, 'index']);
    Route::post('/alerts/{alert}/acknowledge', [AdminAlertController::class, 'acknowledge'])->whereNumber('alert');
    Route::post('/alerts/{alert}/resolve', [AdminAlertController::class, 'resolve'])->whereNumber('alert');
    Route::post('/alerts/{alert}/escalate', [AdminAlertController::class, 'escalate'])->whereNumber('alert');
    Route::get('/incidents', [AdminIncidentController::class, 'index']);
    Route::post('/incidents', [AdminIncidentController::class, 'store']);
    Route::get('/incidents/{incident}', [AdminIncidentController::class, 'show'])->whereNumber('incident');
    Route::patch('/incidents/{incident}', [AdminIncidentController::class, 'update'])->whereNumber('incident');
    Route::post('/incidents/{incident}/notes', [AdminIncidentController::class, 'addNote'])->whereNumber('incident');
    Route::get('/operations', [AdminOperationController::class, 'index']);
    Route::get('/operations/{operation}', [AdminOperationController::class, 'show'])->whereNumber('operation');
    Route::get('/operations/{operation}/download', [AdminOperationController::class, 'download'])->whereNumber('operation');
    Route::post('/exports', [AdminOperationController::class, 'export'])->middleware('throttle:10,1');
    Route::get('/maintenance', [AdminMaintenanceController::class, 'index']);
    Route::post('/maintenance', [AdminMaintenanceController::class, 'store']);
    Route::post('/maintenance/{window}/cancel', [AdminMaintenanceController::class, 'cancel'])->whereNumber('window');

    // Data and AI governance
    Route::get('/data-quality', [AdminDataQualityController::class, 'index']);
    Route::post('/data-quality/scan', [AdminDataQualityController::class, 'scan'])->middleware('throttle:6,1');
    Route::patch('/data-quality/{finding}', [AdminDataQualityController::class, 'update'])->whereNumber('finding');
    Route::get('/ai', [AdminAiGovernanceController::class, 'index']);
    Route::get('/ai-usage', [AdminAiUsageController::class, 'index']);

    // Settings and recovery
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::put('/settings', [AdminSettingsController::class, 'update']);
    Route::get('/backups', [AdminBackupController::class, 'index']);
    Route::post('/backups', [AdminBackupController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/backups/{backup}/verify', [AdminBackupController::class, 'verify'])->whereNumber('backup')->middleware('throttle:10,1');
    Route::get('/backups/{backup}/download', [AdminBackupController::class, 'download'])->whereNumber('backup');
    Route::delete('/backups/{backup}', [AdminBackupController::class, 'destroy'])->whereNumber('backup');
});

Route::middleware('auth:sanctum')->patch('/users/me', [AuthController::class, 'updateProfile']);

/**
 * Faculty profile picture (private storage; the target account is always the session user for mutations)
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/profile', [AuthController::class, 'updateProfile']);
    Route::get('/profile/picture', [ProfilePictureController::class, 'show']);
    Route::post('/profile/picture', [ProfilePictureController::class, 'store'])->middleware('throttle:profile-picture');
    Route::delete('/profile/picture', [ProfilePictureController::class, 'destroy'])->middleware('throttle:profile-picture');
    Route::get('/users/{user}/profile-picture', [ProfilePictureController::class, 'showUser'])->whereNumber('user');
});

/**
 * Protected Faculty, Course & Assessment Management Endpoints
 */
Route::middleware('auth:sanctum')->group(function () {
    // Course CRUD
    // Global search across accessible records
    Route::get('/search', SearchController::class)->middleware('throttle:60,1');

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

    // Faculty-authored assessment questions (add / edit / delete, or import from the uploaded paper after review)
    Route::get('/assessments/{assessment}/questions', [AssessmentQuestionController::class, 'index']);
    Route::post('/assessments/{assessment}/questions', [AssessmentQuestionController::class, 'store']);
    Route::post('/assessments/{assessment}/questions/bulk', [AssessmentQuestionController::class, 'bulkStore']);
    Route::post('/assessments/{assessment}/question-paper/extract', [AssessmentQuestionController::class, 'extract'])->middleware('throttle:uploads');
    Route::put('/questions/{question}', [AssessmentQuestionController::class, 'update'])->whereNumber('question');
    Route::delete('/questions/{question}', [AssessmentQuestionController::class, 'destroy'])->whereNumber('question');

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
    Route::prefix('ai')->middleware(['throttle:ai-analysis', 'feature:ai_analysis'])->group(function () {
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
    Route::post('/questions/{question}/rubrics/generate', [RubricController::class, 'generate'])->middleware(['throttle:ai-analysis', 'feature:ai_grading']);
    Route::get('/questions/{question}/rubrics', [RubricController::class, 'index']);
    Route::get('/rubrics/{rubric}', [RubricController::class, 'show']);
    Route::put('/rubrics/{rubric}', [RubricController::class, 'update']);
    Route::delete('/rubrics/{rubric}', [RubricController::class, 'destroy']);
    Route::post('/rubrics/{rubric}/approve', [RubricController::class, 'approve']);
    Route::post('/rubrics/{rubric}/regenerate', [RubricController::class, 'regenerate'])->middleware(['throttle:ai-analysis', 'feature:ai_grading']);

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
    Route::post('/student-answers/{answer}/ai-grade', [AiGradingController::class, 'request'])->middleware(['throttle:ai-analysis', 'feature:ai_grading']);
    Route::get('/student-answers/{answer}/ai-grading', [AiGradingController::class, 'show']);
    Route::get('/student-answers/{answer}/ai-grading/history', [AiGradingController::class, 'history']);
    Route::post('/student-answers/{answer}/finalize-grade', [AiGradingController::class, 'finalizeGrade']);
    Route::put('/student-answers/{answer}/final-grade', [AiGradingController::class, 'finalizeGrade']);
    Route::post('/ai-grading/{result}/regenerate', [AiGradingController::class, 'regenerate'])->middleware(['throttle:ai-analysis', 'feature:ai_grading']);
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
    Route::post('/assessments/{assessment}/performance/class-summary', [PerformanceController::class, 'classSummary'])->middleware('feature:ai_class_summary');
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
    Route::prefix('academic-chat')->middleware('feature:academic_chat')->group(function () {
        Route::get('/sessions', [AcademicChatController::class, 'index']);
        Route::post('/sessions', [AcademicChatController::class, 'store']);
        Route::get('/sessions/{session}', [AcademicChatController::class, 'show']);
        Route::patch('/sessions/{session}', [AcademicChatController::class, 'update']);
        Route::get('/sessions/{session}/export', [AcademicChatController::class, 'export']);
        Route::delete('/sessions/{session}', [AcademicChatController::class, 'destroy']);
        Route::post('/sessions/{session}/messages', [AcademicChatController::class, 'sendMessage'])->middleware('throttle:academic-chat');
    });

    // STEP 33: Constrained Question Generator (drafts only; approval + explicit add-to-assessment required)
    Route::get('/question-generation', [QuestionGenerationController::class, 'index']);
    Route::post('/question-generation', [QuestionGenerationController::class, 'store'])->middleware(['throttle:question-generation', 'feature:question_generation']);
    Route::get('/question-generation/{generation}', [QuestionGenerationController::class, 'show']);
    Route::get('/question-generation/{generation}/questions', [QuestionGenerationController::class, 'questions']);
    Route::post('/question-generation/{generation}/regenerate', [QuestionGenerationController::class, 'regenerate'])->middleware(['throttle:question-generation', 'feature:question_generation']);
    Route::put('/generated-questions/{generatedQuestion}', [QuestionGenerationController::class, 'update']);
    Route::post('/generated-questions/{generatedQuestion}/approve', [QuestionGenerationController::class, 'approve']);
    Route::post('/generated-questions/{generatedQuestion}/reject', [QuestionGenerationController::class, 'reject']);
    Route::post('/generated-questions/{generatedQuestion}/regenerate', [QuestionGenerationController::class, 'regenerateQuestion'])->middleware(['throttle:question-generation', 'feature:question_generation']);
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

    // STEP 47: Notification center (owner-scoped; ids are uuids) + per-type preferences
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);
    Route::patch('/notification-preferences/{type}', [NotificationPreferenceController::class, 'patch']);

    // E-mail system: status, own delivery log, developer preview, operator test (never exposes SMTP configuration)
    Route::get('/email/status', [EmailController::class, 'status']);
    Route::get('/email/deliveries', [EmailController::class, 'deliveries']);
    Route::get('/email/deliveries/{delivery}', [EmailController::class, 'delivery'])->whereNumber('delivery');
    Route::get('/email/preview/{template}', [EmailController::class, 'preview']);
    Route::post('/email/test', [EmailController::class, 'test'])->middleware('throttle:email-test');

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

    // STEP 35: AI Evaluation & Model Performance (evaluation is separate from production AI).
    // Administrator only: it lives in the admin control center, not the faculty workspace.
    Route::prefix('ai/evaluation')->middleware('admin')->group(function () {
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

    // Exam paper builder: blueprint/target-driven papers from the course question pool, exam sets, variants, export
    Route::get('/assessments/{assessment}/exam-papers', [ExamPaperController::class, 'index']);
    Route::post('/assessments/{assessment}/exam-papers', [ExamPaperController::class, 'store']);
    Route::get('/assessments/{assessment}/question-pool', [ExamPaperController::class, 'pool']);
    Route::prefix('exam-papers/{paper}')->whereNumber('paper')->group(function () {
        Route::get('/', [ExamPaperController::class, 'show']);
        Route::patch('/', [ExamPaperController::class, 'update']);
        Route::delete('/', [ExamPaperController::class, 'destroy']);
        Route::post('/items', [ExamPaperController::class, 'addItem']);
        Route::patch('/items/{item}', [ExamPaperController::class, 'updateItem'])->whereNumber('item');
        Route::delete('/items/{item}', [ExamPaperController::class, 'destroyItem'])->whereNumber('item');
        Route::put('/order', [ExamPaperController::class, 'reorder']);
        Route::post('/items/{item}/variants', [ExamPaperController::class, 'variants'])->whereNumber('item')->middleware(['throttle:question-generation', 'feature:question_generation']);
        Route::post('/sets', [ExamPaperController::class, 'createSets'])->middleware(['throttle:question-generation']);
        Route::post('/apply', [ExamPaperController::class, 'apply']);
        Route::get('/export', [ExamPaperController::class, 'export']);
    });

    // Post-exam integrity: item analysis (aggregate) and the answer plagiarism check (student data)
    Route::get('/assessments/{assessment}/item-analysis', [AssessmentIntegrityController::class, 'itemAnalysis']);
    Route::get('/assessments/{assessment}/plagiarism-checks/latest', [AssessmentIntegrityController::class, 'latestSimilarity']);
    Route::post('/assessments/{assessment}/plagiarism-checks', [AssessmentIntegrityController::class, 'runSimilarity'])->middleware('throttle:ai-analysis');
    Route::patch('/plagiarism-matches/{match}', [AssessmentIntegrityController::class, 'reviewMatch'])->whereNumber('match');

    // Planning: course quality across semesters, workload, OBE accreditation pack, course file archive, department view
    Route::get('/courses/{course}/quality-trend', [PlanningController::class, 'qualityTrend']);
    Route::get('/courses/{course}/obe', [PlanningController::class, 'obe']);
    Route::get('/courses/{course}/obe-pack', [PlanningController::class, 'obePack'])->middleware('throttle:20,1');
    Route::get('/courses/{course}/archive', [PlanningController::class, 'archive'])->middleware('throttle:10,1');
    Route::get('/workload', [PlanningController::class, 'workload']);
    Route::get('/department/overview', [PlanningController::class, 'department']);

    // AI course design: syllabus (COs + CO→PO), lesson plans; research paper summaries; mobile grading queue
    Route::get('/courses/{course}/plans', [CourseDesignController::class, 'plans']);
    Route::post('/courses/{course}/syllabus-draft', [CourseDesignController::class, 'draftSyllabus'])->middleware(['throttle:ai-analysis', 'feature:ai_analysis']);
    Route::post('/courses/{course}/syllabus-draft/{plan}/apply', [CourseDesignController::class, 'applySyllabus'])->whereNumber('plan');
    Route::post('/courses/{course}/lesson-plan', [CourseDesignController::class, 'lessonPlan'])->middleware(['throttle:ai-analysis', 'feature:ai_analysis']);
    Route::post('/course-plans/{plan}/schedule', [CourseDesignController::class, 'scheduleLessonPlan'])->whereNumber('plan');
    Route::get('/course-plans/{plan}/export', [CourseDesignController::class, 'export'])->whereNumber('plan');
    Route::get('/research/summaries', [ResearchController::class, 'index']);
    Route::post('/research/summaries', [ResearchController::class, 'store'])->middleware(['throttle:uploads', 'feature:ai_analysis']);
    Route::get('/research/summaries/{summary}', [ResearchController::class, 'show'])->whereNumber('summary');
    Route::delete('/research/summaries/{summary}', [ResearchController::class, 'destroy'])->whereNumber('summary');
    Route::get('/assessments/{assessment}/grading-queue', GradingQueueController::class);

    // AI toolkit: Bangla <-> English translation (sync), handwriting OCR and lecture transcription (queued, owner-only)
    Route::prefix('ai-toolkit')->group(function () {
        Route::get('/', [AiToolkitController::class, 'index']);
        Route::post('/translate', [AiToolkitController::class, 'translate'])->middleware(['throttle:ai-analysis', 'feature:ai_translation']);
        Route::post('/ocr', [AiToolkitController::class, 'ocr'])->middleware(['throttle:uploads', 'feature:ai_ocr']);
        Route::post('/transcribe', [AiToolkitController::class, 'transcribe'])->middleware(['throttle:uploads', 'feature:ai_transcription']);
        Route::post('/study-pack', [AiToolkitController::class, 'studyPack'])->middleware(['throttle:ai-analysis', 'feature:ai_study_pack']);
        Route::post('/speak', [AiToolkitController::class, 'speak'])->middleware(['throttle:ai-analysis', 'feature:ai_speech']);
        Route::post('/question-quality', [AiToolkitController::class, 'questionQuality'])->middleware(['throttle:ai-analysis', 'feature:ai_writing']);
        Route::post('/simplify', [AiToolkitController::class, 'simplify'])->middleware(['throttle:ai-analysis', 'feature:ai_writing']);
        Route::post('/bloom-rewrite', [AiToolkitController::class, 'bloomRewrite'])->middleware(['throttle:ai-analysis', 'feature:ai_writing']);
        Route::post('/feedback-draft', [AiToolkitController::class, 'feedbackDraft'])->middleware(['throttle:ai-analysis', 'feature:ai_feedback']);
        Route::post('/compare-answers', [AiToolkitController::class, 'compareAnswers'])->middleware(['throttle:ai-analysis', 'feature:ai_answer_compare']);
        Route::post('/paper-review', [AiToolkitController::class, 'paperReview'])->middleware(['throttle:ai-analysis', 'feature:ai_paper_review']);
        Route::post('/jobs/{job}/attach', [AiToolkitController::class, 'attachToSubmission'])->whereNumber('job');
        Route::post('/jobs/{job}/question-bank', [AiToolkitController::class, 'saveToQuestionBank'])->whereNumber('job');
        Route::get('/jobs/{job}', [AiToolkitController::class, 'show'])->whereNumber('job');
        Route::get('/jobs/{job}/download', [AiToolkitController::class, 'download'])->whereNumber('job');
        Route::delete('/jobs/{job}', [AiToolkitController::class, 'destroy'])->whereNumber('job');
    });

    // Academic calendar (own events + dated assessments; reminders via notifications; .ics export)
    Route::get('/calendar/events', [CalendarController::class, 'index']);
    Route::post('/calendar/events', [CalendarController::class, 'store']);
    Route::patch('/calendar/events/{event}', [CalendarController::class, 'update'])->whereNumber('event');
    Route::delete('/calendar/events/{event}', [CalendarController::class, 'destroy'])->whereNumber('event');
    Route::get('/calendar/export.ics', [CalendarController::class, 'ics']);

    // Personal feedback comment bank (grading)
    Route::get('/feedback-comments', [FeedbackCommentController::class, 'index']);
    Route::post('/feedback-comments', [FeedbackCommentController::class, 'store']);
    Route::patch('/feedback-comments/{comment}', [FeedbackCommentController::class, 'update'])->whereNumber('comment');
    Route::delete('/feedback-comments/{comment}', [FeedbackCommentController::class, 'destroy'])->whereNumber('comment');
    Route::post('/feedback-comments/{comment}/use', [FeedbackCommentController::class, 'use'])->whereNumber('comment');

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

// Public "Faculty dispatch" newsletter (double opt-in; token links arrive by e-mail)
Route::prefix('newsletter')->group(function () {
    Route::post('/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter');
    Route::post('/confirm/{token}', [NewsletterController::class, 'confirm'])->middleware('throttle:auth');
    Route::post('/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'])->middleware('throttle:auth');
});

