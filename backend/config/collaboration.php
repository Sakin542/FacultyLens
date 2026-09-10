<?php

/**
 * STEP 34: Faculty Collaboration — roles, permission matrix, limits.
 * The matrix is the single source of truth used by CourseAccessService and every policy.
 */
return [
    'roles' => ['OWNER', 'EDITOR', 'REVIEWER', 'VIEWER'],

    // ability => roles allowed
    'matrix' => [
        'view' => ['OWNER', 'EDITOR', 'REVIEWER', 'VIEWER'],
        'view_documents' => ['OWNER', 'EDITOR', 'REVIEWER', 'VIEWER'],
        'download_documents' => ['OWNER', 'EDITOR', 'REVIEWER'],
        'upload_documents' => ['OWNER', 'EDITOR'],
        'manage_documents' => ['OWNER', 'EDITOR'],
        'edit_course' => ['OWNER', 'EDITOR'],
        'delete_course' => ['OWNER'],
        'create_assessment' => ['OWNER', 'EDITOR'],
        'edit_assessment' => ['OWNER', 'EDITOR'],
        'delete_assessment' => ['OWNER'],
        'edit_question' => ['OWNER', 'EDITOR'],
        'view_analysis' => ['OWNER', 'EDITOR', 'REVIEWER', 'VIEWER'],
        'run_analysis' => ['OWNER', 'EDITOR'],
        'approve_recommendation' => ['OWNER', 'EDITOR'],
        'generate_questions' => ['OWNER', 'EDITOR'],
        'approve_generated_question' => ['OWNER', 'EDITOR'],
        'approve_rubric' => ['OWNER', 'EDITOR'],
        'comment' => ['OWNER', 'EDITOR', 'REVIEWER'], // VIEWER added when viewer_can_comment
        'view_student_data' => ['OWNER', 'EDITOR'],
        'manage_collaborators' => ['OWNER'],
        'transfer_ownership' => ['OWNER'],
    ],

    'viewer_can_comment' => (bool) env('COLLABORATION_VIEWER_CAN_COMMENT', false),

    'invitation_expires_days' => (int) env('COLLABORATION_INVITATION_EXPIRES_DAYS', 7),
    'invite_rate_limit_per_hour' => (int) env('COLLABORATION_INVITE_RATE_LIMIT', 10),
    'comment_rate_limit_per_minute' => (int) env('COLLABORATION_COMMENT_RATE_LIMIT', 60),
    'max_comment_length' => (int) env('COLLABORATION_MAX_COMMENT_LENGTH', 5000),
    'activity_per_page' => 20,
    'max_collaborators_per_course' => (int) env('COLLABORATION_MAX_COLLABORATORS', 50),

    // Polymorphic comment targets → model class (allow-list; anything else is rejected)
    'commentables' => [
        'course' => \App\Models\Course::class,
        'assessment' => \App\Models\Assessment::class,
        'question' => \App\Models\Question::class,
        'analysis_report' => \App\Models\AnalysisReport::class,
        'recommendation' => \App\Models\Recommendation::class,
        'generated_question' => \App\Models\GeneratedQuestion::class,
        'rubric' => \App\Models\Rubric::class,
        'learning_outcome' => \App\Models\LearningOutcome::class,
        'document' => \App\Models\DocumentProcessing::class,
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
