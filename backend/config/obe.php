<?php

/**
 * Outcome-based education (OBE) attainment settings used by the accreditation pack and course quality trends.
 * Defaults follow the common 0–3 attainment scale; set them to your institution's policy.
 */
return [
    // A course outcome is "attained" when the class average on its questions reaches this percentage
    'co_target_percent' => (float) env('OBE_CO_TARGET_PERCENT', 60),

    // Attainment level from the class average on a CO: [minimum %, level], checked from the top
    'levels' => [
        [70, 3],
        [60, 2],
        [50, 1],
        [0, 0],
    ],

    // A program outcome is attained when its weighted level reaches this value (0–3 scale)
    'po_target_level' => (float) env('OBE_PO_TARGET_LEVEL', 2.0),

    // Bloom levels counted as higher-order thinking
    'higher_order_levels' => ['Analyze', 'Evaluate', 'Create'],

    // Workload estimates (minutes of faculty time) shown with their formula on the dashboard
    'workload' => [
        'minutes_per_ungraded_answer' => (int) env('WORKLOAD_MINUTES_PER_ANSWER', 4),
        'prep_hours_per_upcoming_exam' => (float) env('WORKLOAD_PREP_HOURS_PER_EXAM', 3),
    ],
];
