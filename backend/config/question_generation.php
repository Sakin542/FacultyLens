<?php

/**
 * STEP 33: Constrained Question Generator. Engineering defaults — tune per deployment.
 */
return [
    'max_questions_per_request' => (int) env('QUESTION_GENERATION_MAX_QUESTIONS', 20),
    'max_marks_per_question' => (float) env('QUESTION_GENERATION_MAX_MARKS', 100),
    'max_regenerations_per_request' => (int) env('QUESTION_GENERATION_MAX_REGENERATIONS', 3),
    'rate_limit_per_minute' => (int) env('QUESTION_GENERATION_RATE_LIMIT', 10),
    'max_topic_length' => 255,

    // Document grounding (reuses STEP 32 retrieval)
    'document_top_k' => (int) env('QUESTION_GENERATION_DOC_TOP_K', 6),
    'document_min_relevance' => (float) env('QUESTION_GENERATION_DOC_MIN_RELEVANCE', 0.25),

    // Existing-question similarity context sent to the AI service
    'max_existing_questions' => (int) env('QUESTION_GENERATION_MAX_EXISTING', 300),

    // Thresholds (STEP 12 / STEP 11) forwarded to the AI service
    'similarity_thresholds' => ['duplicate' => 0.85, 'high' => 0.70, 'moderate' => 0.50],
    'alignment_thresholds' => ['strong' => 0.70, 'weak' => 0.50],

    'question_types' => ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false', 'conceptual', 'analytical'],
    'difficulty_levels' => ['easy', 'medium', 'hard'],
    'cognitive_levels' => ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'],
    'feedback_reasons' => ['too_easy', 'too_difficult', 'wrong_topic', 'wrong_co', 'too_similar', 'poor_wording', 'not_appropriate', 'other'],

    'prompt_version' => env('QUESTION_GENERATION_PROMPT_VERSION', '1.0.0'),
];
