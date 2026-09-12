<?php

/*
 * STEP 45: AI explainability & transparency.
 *
 * Explanations are derived deterministically from stored AI results (scores, labels, thresholds, evidence) —
 * never from hidden reasoning or prompts. Each result type maps to a method type, limitations, an optional
 * STEP 35/44 evaluation task, and the faculty actions that are appropriate for it.
 */
return [
    'explanation_version' => env('AI_EXPLANATION_VERSION', '1.0.0'),

    // Seconds to cache rule-based cue evidence fetched from the AI service (keyed by question + labels).
    'cue_cache_ttl' => (int) env('AI_EXPLANATION_CUE_CACHE_TTL', 21600),

    'embedding_model' => env('AI_EMBEDDING_MODEL', 'sentence-transformers/all-MiniLM-L6-v2'),

    'method_types' => ['RULE_BASED', 'MODEL_BASED', 'EMBEDDING_BASED', 'HYBRID', 'GENERATIVE', 'HUMAN_CONFIRMED'],

    'result_types' => [
        'question_type', 'difficulty', 'bloom', 'topic',
        'lo_alignment', 'co_po_mapping', 'similarity',
        'assessment_quality', 'recommendation', 'rubric',
        'generated_question', 'rag_answer', 'ai_grading', 'inter_grader',
    ],

    // Result type → STEP 35 evaluation task (null = no evaluation task exists for this component).
    'evaluation_tasks' => [
        'question_type' => 'QUESTION_CLASSIFICATION',
        'difficulty' => 'DIFFICULTY_CLASSIFICATION',
        'bloom' => 'BLOOM_CLASSIFICATION',
        'topic' => null,
        'lo_alignment' => 'LO_ALIGNMENT',
        'co_po_mapping' => null,
        'similarity' => 'SIMILARITY',
        'assessment_quality' => null,
        'recommendation' => null,
        'rubric' => 'RUBRIC_GENERATION',
        'generated_question' => 'QUESTION_GENERATION',
        'rag_answer' => 'DOCUMENT_CHAT',
        'ai_grading' => 'GRADING_ASSISTANCE',
        'inter_grader' => null,
    ],

    'alignment_thresholds' => ['strong' => 0.70, 'weak' => 0.50],
    'similarity_thresholds' => ['duplicate' => 0.85, 'high' => 0.70, 'moderate' => 0.50],
    'quality_weights' => ['topic' => 20.0, 'learning_outcome' => 20.0, 'difficulty' => 15.0, 'cognitive' => 15.0, 'question_diversity' => 15.0, 'marks' => 15.0],
    'difficulty_targets' => ['easy' => 30.0, 'medium' => 50.0, 'hard' => 20.0],

    'limitations' => [
        'question_type' => ['Question-type classification is rule-based and depends on the wording of the question.', 'This result is AI-assisted and should be reviewed.'],
        'difficulty' => ['Difficulty is an AI-assisted estimate and may vary by learner population and course context.'],
        'bloom' => ['Bloom classification can involve expert judgment; the detected verb may not reflect the full task demand.'],
        'topic' => ['Topic detection depends on the course topics defined and the wording of the question.'],
        'lo_alignment' => ['Semantic similarity to a learning outcome description does not guarantee the question assesses that outcome.', 'The score is a cosine similarity, not a probability.'],
        'co_po_mapping' => ['An AI-suggested mapping is a starting point for faculty review; it does not imply accreditation compliance.'],
        'similarity' => ['Semantic similarity does not prove that two questions are duplicates.', 'The score is a cosine similarity, not a probability that the questions are the same.'],
        'assessment_quality' => ['Quality dimensions use configured weights and targets; they describe balance, not pedagogical correctness.', 'Dimensions without data are excluded and the remaining weights are normalized.'],
        'recommendation' => ['Recommendations are derived from configured thresholds and describe deviations; they are not instructions.'],
        'rubric' => ['A generated rubric is a DRAFT built from the question wording and assessment metadata; faculty approval is required before use.'],
        'generated_question' => ['Passing all constraints does not mean a generated question is academically correct; faculty review is required.'],
        'rag_answer' => ['Answers depend on the quality and coverage of the selected documents.', 'Retrieved passages are treated as untrusted data and may be incomplete.'],
        'ai_grading' => ['AI grading is advisory and requires faculty review; the suggested mark is never final.', 'Rubric coverage detection may miss valid answers that use different wording.'],
        'inter_grader' => ['The FacultyLens Agreement Indicator is an internal descriptive indicator, not an official reliability statistic.', 'AI-suggested marks are excluded from agreement calculations.'],
    ],

    'review_actions' => ['ACCEPTED', 'REJECTED', 'REVIEWED'],

    'override_reasons' => [
        'AI_CLASSIFICATION_INCORRECT' => 'AI classification incorrect',
        'INSUFFICIENT_CONTEXT' => 'Insufficient context',
        'COURSE_SPECIFIC_INTERPRETATION' => 'Course-specific interpretation',
        'ACADEMIC_JUDGMENT' => 'Academic judgment',
        'OTHER' => 'Other',
    ],

    // Client-reported view events that may be audited (anything else is rejected).
    'view_events' => ['AI_RESULT_VIEWED', 'AI_EVIDENCE_VIEWED', 'AI_SOURCE_OPENED'],

    'audit_actions' => [
        'AI_RESULT_VIEWED', 'AI_EXPLANATION_VIEWED', 'AI_EVIDENCE_VIEWED', 'AI_RESULT_ACCEPTED', 'AI_RESULT_REJECTED',
        'AI_RESULT_OVERRIDDEN', 'AI_SOURCE_OPENED', 'AI_FEEDBACK_SUBMITTED',
    ],
];
