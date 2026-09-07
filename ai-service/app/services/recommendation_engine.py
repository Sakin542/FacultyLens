"""Recommendation Engine Orchestrator for FacultyLens.

Coordinates problem detection, evidence compilation, recommendation generation,
and priority ranking for faculty academic decision support.
"""

from typing import List, Dict, Any, Optional
from app.schemas.recommendation import (
    RecommendationRequest,
    RecommendationResponse,
    RecommendationItem,
    RecommendationPriority,
)
from app.services.problem_detector import ProblemDetector
from app.services.recommendation_generator import RecommendationGenerator


class RecommendationEngine:
    """Orchestrator for FacultyLens AI Recommendation generation."""

    def __init__(self, custom_thresholds: Optional[Dict[str, Any]] = None):
        self.detector = ProblemDetector(custom_thresholds)
        self.generator = RecommendationGenerator()

    def generate(self, request: RecommendationRequest) -> RecommendationResponse:
        # 1. Detect problems across all 7 evaluation dimensions
        problems = self.detector.detect_problems(request)

        # 2. Convert detected problems into evidence-grounded recommendations
        recommendations = self.generator.generate_recommendations(problems)

        # 3. Sort by priority: HIGH -> MEDIUM -> LOW
        priority_order = {
            RecommendationPriority.HIGH.value: 1,
            RecommendationPriority.MEDIUM.value: 2,
            RecommendationPriority.LOW.value: 3,
        }
        recommendations.sort(key=lambda r: priority_order.get(r.priority, 99))

        # 4. Count priorities
        high_cnt = sum(1 for r in recommendations if r.priority == RecommendationPriority.HIGH.value)
        med_cnt = sum(1 for r in recommendations if r.priority == RecommendationPriority.MEDIUM.value)
        low_cnt = sum(1 for r in recommendations if r.priority == RecommendationPriority.LOW.value)

        return RecommendationResponse(
            status="success",
            method="evidence_based_recommendation_engine",
            total_recommendations=len(recommendations),
            high_priority_count=high_cnt,
            medium_priority_count=med_cnt,
            low_priority_count=low_cnt,
            recommendations=recommendations,
        )

