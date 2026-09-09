<?php

/**
 * STEP 30: Student Performance / Gap Analysis.
 *
 * All thresholds are INITIAL engineering values, not universal academic standards.
 * Institutions should calibrate them against their own grading practices.
 */
return [

    // Benchmark against which the performance gap is measured (percentage points).
    'expected_performance_percent' => (float) env('PERFORMANCE_EXPECTED_PERCENT', 70),

    // Performance >= this is reported as STRONG.
    'strong_performance_percent' => (float) env('PERFORMANCE_STRONG_PERCENT', 80),

    // Gap = expected - actual (percentage points). Classification bands:
    //   gap <  low       -> ON_TARGET (or STRONG when >= strong_performance_percent)
    //   low..<moderate   -> MINOR_GAP
    //   moderate..<high  -> MODERATE_GAP
    //   >= high          -> HIGH_GAP
    'gap_low_threshold' => (float) env('PERFORMANCE_GAP_LOW', 5),
    'gap_moderate_threshold' => (float) env('PERFORMANCE_GAP_MODERATE', 10),
    'gap_high_threshold' => (float) env('PERFORMANCE_GAP_HIGH', 20),

    // Below this many finalized responses an aggregate is reported as INSUFFICIENT_DATA.
    'min_responses_for_gap_analysis' => (int) env('PERFORMANCE_MIN_RESPONSES', 5),

    // A mark counts as finalized when the answer is REVIEWED with awarded marks and its
    // submission's grading_status is one of these (AI_ASSISTED / IN_PROGRESS are excluded).
    'finalized_grading_statuses' => ['FACULTY_REVIEWED', 'FINALIZED'],

    // STEP 11 alignment levels that let a question count toward a learning outcome when the
    // faculty did not assign the LO explicitly on the question.
    'lo_alignment_levels' => ['STRONG_ALIGNMENT'],

    // Above this many finalized answers the analysis is queued instead of computed inline.
    'async_threshold' => (int) env('PERFORMANCE_ASYNC_THRESHOLD', 500),

    'cache_ttl' => (int) env('PERFORMANCE_CACHE_TTL', 600),
];
