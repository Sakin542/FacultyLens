<?php

/**
 * STEP 38: Assessment Versioning — immutable historical versions of an assessment.
 * All rules are deterministic (no AI): numbering, labels, cloning, comparison, validation.
 */
return [
    // Same enums as STEP 33 / STEP 37 — do not create incompatible duplicates
    'question_types' => ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false', 'conceptual', 'analytical', 'other'],
    'difficulty_levels' => ['easy', 'medium', 'hard'],
    'cognitive_levels' => ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'],
    'assessment_types' => ['quiz', 'midterm', 'final', 'assignment', 'lab', 'project', 'presentation', 'viva', 'other'],

    // Question-level fields compared deterministically between two versions
    'compared_question_fields' => ['question_number', 'question_text', 'question_type', 'marks', 'difficulty_level', 'cognitive_level', 'learning_outcome_id', 'program_outcome_id', 'topic', 'expected_answer', 'section_name'],
    // Changes to any of these are structural → detected change type MAJOR; anything else → MINOR
    'structural_fields' => ['question_type', 'marks', 'difficulty_level', 'cognitive_level', 'learning_outcome_id', 'program_outcome_id', 'question_number'],

    // Blueprint / question-profile comparison: |a - b| >= this many percentage points is reported as a change
    'distribution_change_threshold' => (float) env('VERSION_DISTRIBUTION_CHANGE_THRESHOLD', 0.5),
    // Finalization: planned vs actual distribution deviation beyond this is a warning (uses STEP 37 tolerance by default)
    'blueprint_tolerance' => (float) env('VERSION_BLUEPRINT_TOLERANCE', env('BLUEPRINT_PERCENTAGE_TOLERANCE', 5)),
    'marks_tolerance' => 0.01,

    // Finalization policy
    'require_learning_outcome_mapping' => (bool) env('VERSION_REQUIRE_LO_MAPPING', false),
    'require_program_outcome_mapping' => false,
    'require_change_summary' => true,

    'max_questions' => 200,
    'max_marks' => 1000,
    'history_per_page' => 25,
];
