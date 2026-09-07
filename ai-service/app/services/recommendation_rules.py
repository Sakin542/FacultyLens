"""Rule definitions and threshold configuration for the AI Recommendation Engine.

All rules are deterministic, testable, and pedagogically grounded in university
assessment quality principles.
"""

from typing import Dict, Any


DEFAULT_THRESHOLDS: Dict[str, Any] = {
    # Topic thresholds
    "topic_low_coverage_percent": 50.0,
    # LO thresholds
    "lo_weak_similarity_threshold": 0.50,
    "lo_strong_similarity_threshold": 0.70,
    # Difficulty deviation thresholds
    "difficulty_max_deviation_warn": 30.0,
    "difficulty_single_band_deviation_warn": 20.0,
    # Cognitive thresholds
    "cognitive_concentration_warn_percent": 60.0,
    "cognitive_lower_order_warn_percent": 70.0,
    "cognitive_min_entropy_score": 45.0,
    # Question format thresholds
    "question_format_single_type_warn_percent": 80.0,
    # Marks distribution thresholds
    "marks_single_question_high_concentration": 40.0,
    "marks_single_question_warn_concentration": 30.0,
    # Semantic similarity thresholds
    "similarity_duplicate_threshold": 0.85,
    "similarity_high_threshold": 0.70,
    # Overall quality thresholds
    "quality_critical_score": 50.0,
    "quality_warning_score": 65.0,
}


class RuleCodes:
    # Topic
    TOPIC_NOT_COVERED = "TOPIC_NOT_COVERED"
    TOPIC_LOW_COVERAGE = "TOPIC_LOW_COVERAGE"

    # LO
    LO_NOT_COVERED = "LO_NOT_COVERED"
    LO_WEAKLY_COVERED = "LO_WEAKLY_COVERED"
    LO_UNDERREPRESENTED = "LO_UNDERREPRESENTED"

    # Difficulty
    DIFFICULTY_IMBALANCE = "DIFFICULTY_IMBALANCE"
    DIFFICULTY_EXTREME_SKEW = "DIFFICULTY_EXTREME_SKEW"

    # Cognitive
    COGNITIVE_CONCENTRATION = "COGNITIVE_CONCENTRATION"
    LOWER_ORDER_CONCENTRATION = "LOWER_ORDER_CONCENTRATION"
    LOW_COGNITIVE_DIVERSITY = "LOW_COGNITIVE_DIVERSITY"

    # Question Diversity
    LOW_QUESTION_DIVERSITY = "LOW_QUESTION_DIVERSITY"
    SINGLE_QUESTION_FORMAT = "SINGLE_QUESTION_FORMAT"

    # Marks
    MARKS_MISMATCH = "MARKS_MISMATCH"
    HIGH_MARK_CONCENTRATION = "HIGH_MARK_CONCENTRATION"

    # Similarity
    POTENTIAL_DUPLICATE = "POTENTIAL_DUPLICATE"
    HIGH_SIMILARITY = "HIGH_SIMILARITY"

    # Quality
    LOW_OVERALL_QUALITY = "LOW_OVERALL_QUALITY"

