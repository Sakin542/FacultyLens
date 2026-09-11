<?php

/**
 * STEP 36: Academic Analytics Dashboard.
 * Analytics only reports evidence, trends and signals; it never changes assessments, grades, mappings or models.
 */
return [
    // Aggregates are cached per user + filter fingerprint + data version (see AnalyticsScopeService::dataVersion)
    'cache_ttl' => (int) env('ANALYTICS_CACHE_TTL', 300),

    // STEP 13 difficulty targets (question-count based in analytics; the quality engine also weights by marks)
    'difficulty_targets' => [
        'easy' => (float) env('ANALYTICS_DIFFICULTY_TARGET_EASY', 30),
        'medium' => (float) env('ANALYTICS_DIFFICULTY_TARGET_MEDIUM', 50),
        'hard' => (float) env('ANALYTICS_DIFFICULTY_TARGET_HARD', 20),
    ],
    // Total absolute deviation bands. 45 mirrors the STEP 13 quality-engine "significant deviation" rule; 15 is a reporting band only.
    'difficulty_balance' => [
        'slight_deviation' => (float) env('ANALYTICS_DIFFICULTY_SLIGHT_DEVIATION', 15),
        'significant_deviation' => (float) env('ANALYTICS_DIFFICULTY_SIGNIFICANT_DEVIATION', 45),
    ],

    // Cognitive diversity signal: fewer distinct Bloom levels than this (with >= min questions) is flagged
    'min_cognitive_levels' => (int) env('ANALYTICS_MIN_COGNITIVE_LEVELS', 3),
    'min_questions_for_signals' => (int) env('ANALYTICS_MIN_QUESTIONS_FOR_SIGNALS', 5),

    // Quality rating bands come from AssessmentReportService::getRatingLabel (STEP 13/18)
    'quality_ratings' => ['EXCELLENT', 'GOOD', 'FAIR', 'NEEDS_REVIEW', 'REQUIRES_ATTENTION'],
    'low_quality_ratings' => ['NEEDS_REVIEW', 'REQUIRES_ATTENTION'],

    // LO coverage: an outcome counts as covered when it has at least one STRONG alignment (STEP 11 / performance.lo_alignment_levels)
    'weak_coverage_percent' => (float) env('ANALYTICS_WEAK_COVERAGE_PERCENT', 50),

    'top_gaps_limit' => (int) env('ANALYTICS_TOP_GAPS_LIMIT', 5),
    'attention_limit' => (int) env('ANALYTICS_ATTENTION_LIMIT', 10),
    'trend_points_limit' => (int) env('ANALYTICS_TREND_POINTS_LIMIT', 200),
    'question_table_limit' => (int) env('ANALYTICS_QUESTION_TABLE_LIMIT', 200),

    // Audit actions counted in the collaboration activity trend
    'activity_actions' => [
        'comments' => ['COLLABORATION_COMMENT_CREATED', 'COLLABORATION_COMMENT_RESOLVED', 'COLLABORATION_COMMENT_REOPENED'],
        'reviews' => ['RECOMMENDATION_REVIEWED', 'RECOMMENDATION_ACCEPTED', 'RECOMMENDATION_DISMISSED', 'AI_GRADING_ACCEPTED', 'AI_GRADING_MODIFIED', 'AI_GRADING_REJECTED'],
        'approvals' => ['GENERATED_QUESTION_APPROVED', 'RUBRIC_APPROVED'],
        'question_edits' => ['GENERATED_QUESTION_EDITED', 'QUESTION_UPDATED'],
        'rubric_reviews' => ['RUBRIC_UPDATED', 'RUBRIC_REGENERATED', 'RUBRIC_GENERATED'],
    ],

    'explanations' => [
        'avg_quality' => 'Average of the current STEP 13 overall quality score across analyzed assessments in scope (0–100).',
        'student_performance' => 'Average finalized-grade percentage across submissions with FACULTY_REVIEWED/FINALIZED grading status (STEP 30 rules).',
        'co_coverage' => 'Percentage of learning outcomes with at least one strongly aligned question in the current analysis (STEP 11).',
        'open_gaps' => 'Learning outcomes whose current performance analysis is MINOR_GAP, MODERATE_GAP or HIGH_GAP.',
        'difficulty' => 'Question-count share per difficulty level compared with the configured STEP 13 target profile.',
        'cognitive' => "Question-count share per Bloom level. Institutional targets are not assumed unless configured.",
        'similarity' => 'Similarity categories use the production STEP 12 thresholds (Potential Duplicate ≥ 0.85, Highly Similar ≥ 0.70, Somewhat Similar ≥ 0.50).',
        'grading' => 'AI suggestions are compared with the final faculty grade; the faculty grade is always the official grade.',
        'feedback' => 'Faculty interaction signal — acceptance does not establish that the AI was objectively correct.',
        'ai_evaluation' => 'Headline metrics from completed STEP 35 evaluation runs; tasks without a run are shown as not evaluated.',
        'inter_grader' => 'Inter-grader consistency (STEP 29) is not available in this deployment.',
    ],
];
