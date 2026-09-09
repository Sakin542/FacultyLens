<?php

/**
 * STEP 31: CO/PO Mapping Validator.
 *
 * Thresholds are INITIAL engineering values, not accreditation requirements. Institutions
 * should calibrate them. Nothing here constitutes an accreditation decision.
 */
return [

    // Mapping levels stored as integers; labels are for display only.
    'mapping_levels' => [0 => 'NONE', 1 => 'LOW', 2 => 'MEDIUM', 3 => 'HIGH'],

    // Weight applied to a CO's assessment coverage when contributing to a PO.
    'mapping_weights' => [0 => 0.0, 1 => 1 / 3, 2 => 2 / 3, 3 => 1.0],

    // A CO with assessment coverage below this % of total marks is flagged LOW_CO_COVERAGE.
    'co_min_coverage_percent' => (float) env('COPO_CO_MIN_COVERAGE_PERCENT', 5),

    // A CO holding at least this % of total marks is flagged CO_CONCENTRATION.
    'co_concentration_percent' => (float) env('COPO_CO_CONCENTRATION_PERCENT', 60),

    // PO assessment evidence (sum of mapped CO coverage) below this % is LIMITED_EVIDENCE.
    'po_evidence_min_percent' => (float) env('COPO_PO_EVIDENCE_MIN_PERCENT', 10),

    // Matrix density at or above this % triggers a MAPPING_DENSITY review signal.
    'mapping_density_review_percent' => (float) env('COPO_MAPPING_DENSITY_REVIEW_PERCENT', 90),

    // Share of a CO's marks at cognitive levels below the CO's stated level to flag CO_COGNITIVE_MISMATCH.
    'cognitive_mismatch_percent' => (float) env('COPO_COGNITIVE_MISMATCH_PERCENT', 60),

    // Finding severities are configurable and never imply accreditation failure.
    'severities' => [
        'UNMAPPED_QUESTION' => 'HIGH',
        'UNASSESSED_CO' => 'MEDIUM',
        'LOW_CO_COVERAGE' => 'MEDIUM',
        'CO_CONCENTRATION' => 'LOW',
        'UNMAPPED_PO' => 'LOW',
        'LOW_PO_EVIDENCE' => 'LOW',
        'MAPPING_DENSITY' => 'INFO',
        'CO_COGNITIVE_MISMATCH' => 'LOW',
        'CO_PERFORMANCE_GAP' => 'MEDIUM',
        'MAPPING_REVIEW' => 'LOW',
    ],

    'cache_ttl' => (int) env('COPO_CACHE_TTL', 600),
];
