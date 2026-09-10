<?php

/**
 * STEP 35: AI Evaluation & Model Performance. Evaluation is separate from production AI: nothing here
 * changes thresholds, prompts or models. Quality gates are engineering targets, not academic validity claims.
 */
return [
    'enabled' => (bool) env('AI_EVALUATION_ENABLED', true),
    'batch_size' => (int) env('AI_EVALUATION_BATCH_SIZE', 32),
    'max_examples' => (int) env('AI_EVALUATION_MAX_EXAMPLES', 5000),
    'min_examples_warning' => (int) env('AI_EVALUATION_MIN_EXAMPLES_WARNING', 30),
    'sync_threshold' => (int) env('AI_EVALUATION_SYNC_THRESHOLD', 0), // examples <= this run inline; default always queued

    'tasks' => [
        'QUESTION_CLASSIFICATION', 'DIFFICULTY_CLASSIFICATION', 'BLOOM_CLASSIFICATION', 'LO_ALIGNMENT', 'SIMILARITY',
        'RUBRIC_GENERATION', 'GRADING_ASSISTANCE', 'ANSWER_RUBRIC_ALIGNMENT', 'DOCUMENT_CHAT', 'QUESTION_GENERATION',
    ],
    'splits' => ['TRAIN', 'VALIDATION', 'TEST'],
    'sources' => ['FACULTY_VALIDATED', 'EXPERT_VALIDATED', 'INSTITUTIONAL_DATA', 'CURATED_DATASET'],
    'error_types' => [
        'WRONG_CLASS', 'WRONG_ALIGNMENT', 'FALSE_DUPLICATE', 'MISSED_DUPLICATE', 'WRONG_DIFFICULTY', 'WRONG_BLOOM_LEVEL',
        'UNSUPPORTED_CLAIM', 'WRONG_CITATION', 'MISSED_REFUSAL', 'INJECTION_LEAK', 'CONSTRAINT_VIOLATION', 'MARKS_MISMATCH',
        'LARGE_ERROR', 'INFERENCE_ERROR', 'OTHER',
    ],

    // Label sets used for dataset validation (UPPERCASE, matching the AI service)
    'labels' => [
        'QUESTION_CLASSIFICATION' => ['MCQ', 'SHORT_ANSWER', 'DESCRIPTIVE', 'PROBLEM_SOLVING', 'TRUE_FALSE', 'CONCEPTUAL', 'ANALYTICAL'],
        'DIFFICULTY_CLASSIFICATION' => ['EASY', 'MEDIUM', 'HARD'],
        'BLOOM_CLASSIFICATION' => ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'],
        'LO_ALIGNMENT' => ['STRONG', 'WEAK', 'NOT_ALIGNED'],
        'SIMILARITY' => ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR', 'SOMEWHAT_SIMILAR', 'NOT_SIMILAR'],
        'ANSWER_RUBRIC_ALIGNMENT' => ['FULLY_ALIGNED', 'PARTIALLY_ALIGNED', 'WEAKLY_ALIGNED', 'NOT_ALIGNED'],
    ],

    // Production thresholds (read-only here — evaluation reports against them, never edits them)
    'similarity_thresholds' => ['duplicate' => 0.85, 'high' => 0.70, 'moderate' => 0.50],
    'similarity_sweep' => [0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90],
    'alignment_thresholds' => ['strong' => 0.70, 'weak' => 0.50],
    'grading_tolerances' => [0.5, 1.0, 2.0],
    'grading_large_error' => 2.0,

    // Reporting categories for dataset size — NOT statistical sufficiency claims
    'size_categories' => [
        ['max' => 29, 'label' => 'VERY_LIMITED'],
        ['max' => 99, 'label' => 'LIMITED'],
        ['max' => 499, 'label' => 'MODERATE'],
        ['max' => PHP_INT_MAX, 'label' => 'LARGE'],
    ],

    // Engineering quality gates per task: metric => minimum (or maximum for error metrics)
    'quality_gates' => [
        'QUESTION_CLASSIFICATION' => ['macro_f1' => ['min' => (float) env('AI_EVAL_GATE_QUESTION_F1', 0.80)]],
        'DIFFICULTY_CLASSIFICATION' => ['macro_f1' => ['min' => (float) env('AI_EVAL_GATE_DIFFICULTY_F1', 0.70)]],
        'BLOOM_CLASSIFICATION' => ['macro_f1' => ['min' => (float) env('AI_EVAL_GATE_BLOOM_F1', 0.75)]],
        'LO_ALIGNMENT' => ['macro_f1' => ['min' => (float) env('AI_EVAL_GATE_LO_F1', 0.75)]],
        'SIMILARITY' => ['duplicate_f1' => ['min' => (float) env('AI_EVAL_GATE_SIMILARITY_F1', 0.80)]],
        'RUBRIC_GENERATION' => ['marks_validity_rate' => ['min' => 0.95]],
        'GRADING_ASSISTANCE' => ['mae' => ['max' => (float) env('AI_EVAL_GATE_GRADING_MAE', 1.0)]],
        'ANSWER_RUBRIC_ALIGNMENT' => ['macro_f1' => ['min' => 0.70]],
        'DOCUMENT_CHAT' => ['citation_accuracy' => ['min' => (float) env('AI_EVAL_GATE_RAG_CITATION', 0.90)], 'unsupported_answer_rate' => ['max' => 0.10]],
        'QUESTION_GENERATION' => ['constraint_satisfaction_rate' => ['min' => (float) env('AI_EVAL_GATE_QGEN_CSR', 0.90)]],
    ],

    'rating_dimensions' => [
        'RUBRIC_GENERATION' => ['criterion_relevance', 'criterion_clarity', 'marks_distribution', 'scoring_guidance', 'question_alignment', 'lo_alignment', 'completeness', 'academic_appropriateness'],
        'QUESTION_GENERATION' => ['topic_fit', 'clarity', 'difficulty_fit', 'cognitive_fit', 'academic_appropriateness'],
    ],

    'limitations' => [
        'Ground-truth labels are faculty/expert validated and may contain human disagreement.',
        'Evaluation dataset size may be limited; see the size category and interpret cautiously.',
        'Results may not generalize to all disciplines, languages or question styles.',
        'Generative outputs (rubrics, drafts, chat answers) include subjective quality dimensions.',
        'Faculty grading disagreement does not establish a single correct grade; MAE is a distance, not correctness.',
        'These metrics are engineering monitoring signals, not institutional or accreditation validity.',
    ],
];
