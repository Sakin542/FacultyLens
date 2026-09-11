<?php

/**
 * STEP 37: Assessment Blueprint — planning and validation before questions are selected or generated.
 * Validation is deterministic (no AI calls). Nothing here changes questions, marks or the assessment automatically.
 */
return [
    // Same enums as STEP 33 (config/question_generation.php) — do not create incompatible duplicates
    'question_types' => ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false', 'conceptual', 'analytical'],
    'difficulty_levels' => ['easy', 'medium', 'hard'],
    'cognitive_levels' => ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'],
    'dimensions' => ['DIFFICULTY', 'COGNITIVE_LEVEL', 'LEARNING_OUTCOME', 'PROGRAM_OUTCOME', 'TOPIC', 'QUESTION_TYPE'],

    // STEP 13 default difficulty target (analytics.php carries the same values)
    'difficulty_targets' => ['easy' => 30.0, 'medium' => 50.0, 'hard' => 20.0],

    // Blueprint-vs-actual comparison: |target - actual| <= tolerance → CLOSE; 0 → MATCH; above → MISMATCH
    'percentage_tolerance' => (float) env('BLUEPRINT_PERCENTAGE_TOLERANCE', 5),
    // Percentage totals may deviate from 100 by this much due to rounding without being an error
    'total_rounding_tolerance' => (float) env('BLUEPRINT_TOTAL_ROUNDING_TOLERANCE', 0.5),
    // Marks may deviate by this much (floating point) before it is an error
    'marks_tolerance' => 0.01,

    // Planning indicator only (not an official duration recommendation)
    'time' => [
        'minutes_per_mark_low' => (float) env('BLUEPRINT_MIN_MINUTES_PER_MARK', 1.0),
        'minutes_per_mark_high' => (float) env('BLUEPRINT_MAX_MINUTES_PER_MARK', 3.0),
        // Optional per-type expected minutes per question (faculty/institution may configure)
        'expected_minutes_per_question' => [],
    ],

    // Difficulty imbalance warning when a planned share deviates from target by more than this
    'difficulty_warning_deviation' => (float) env('BLUEPRINT_DIFFICULTY_WARNING_DEVIATION', 15),
    // Warn when a configured outcome/topic has less than this share of marks
    'low_coverage_percent' => (float) env('BLUEPRINT_LOW_COVERAGE_PERCENT', 10),

    // Blueprint completeness = configured planning dimensions / weighted total (planning indicator, not assessment quality)
    'completeness_weights' => [
        'basics' => 15, 'sections' => 20, 'difficulty' => 15, 'cognitive' => 15, 'learning_outcomes' => 20, 'topics' => 5, 'question_types' => 5, 'items' => 5,
    ],

    'max_sections' => 20,
    'max_items' => 100,
    'max_questions' => 200,
    'max_marks' => 1000,
];
