"""Recommendation Generator service for FacultyLens.

Converts detected assessment problems and evidence into actionable, advisory
recommendations for university faculty.
"""

from typing import List, Dict, Any, Optional
import uuid
from app.schemas.recommendation import DetectedProblem, RecommendationItem
from app.services.recommendation_rules import RuleCodes


class RecommendationGenerator:
    """Generates structured, evidence-grounded recommendations using deterministic templates."""

    def generate_recommendations(self, problems: List[DetectedProblem]) -> List[RecommendationItem]:
        recommendations: List[RecommendationItem] = []
        seen_keys = set()

        for problem in problems:
            dedup_key = f"{problem.category.value}:{problem.code}:{str(sorted(problem.evidence.items()))}"
            if dedup_key in seen_keys:
                continue
            seen_keys.add(dedup_key)

            rec_text = self._build_recommendation_text(problem)
            rec_id = f"rec_{problem.category.value[:3]}_{uuid.uuid4().hex[:8]}"

            recommendations.append(
                RecommendationItem(
                    id=rec_id,
                    category=problem.category.value,
                    problem=problem.title,
                    evidence=problem.evidence,
                    explanation=problem.explanation,
                    recommendation=rec_text,
                    priority=problem.severity.value,
                    source_metric=problem.source_metric,
                    status="pending",
                )
            )

        return recommendations

    def _build_recommendation_text(self, problem: DetectedProblem) -> str:
        code = problem.code
        ev = problem.evidence

        if code == RuleCodes.TOPIC_NOT_COVERED:
            topic = ev.get("topic_name", "this topic")
            return (
                f"Consider adding or revising at least one question that directly assesses core concepts "
                f"from '{topic}' to ensure balanced curriculum coverage."
            )

        elif code == RuleCodes.TOPIC_LOW_COVERAGE:
            topic = ev.get("topic_name", "this topic")
            cov = ev.get("coverage_percentage", 0.0)
            return (
                f"Consider increasing the marks or question allocation for '{topic}' (currently {cov:.1f}% "
                f"coverage) to ensure comprehensive module evaluation."
            )

        elif code == RuleCodes.LO_NOT_COVERED:
            lo_code = ev.get("lo_code", "the learning outcome")
            return (
                f"Consider designing or updating an assessment question to specifically target {lo_code}. "
                f"Align the question phrasing and tasks with the competency verbs defined in the LO."
            )

        elif code == RuleCodes.LO_WEAKLY_COVERED:
            lo_code = ev.get("lo_code", "the learning outcome")
            return (
                f"Review existing questions mapped to {lo_code} and consider strengthening the explicit "
                f"connection to the outcome's core objectives."
            )

        elif code == RuleCodes.DIFFICULTY_EXTREME_SKEW:
            if ev.get("easy_percentage") == 0.0:
                return (
                    "Consider introducing foundational, easy-difficulty questions (e.g. definitions, direct concept verification) "
                    "to provide a fair baseline for all students."
                )
            elif ev.get("hard_percentage") == 0.0:
                return (
                    "Consider incorporating at least one advanced, multi-step problem-solving or synthesis question "
                    "to appropriately challenge and distinguish top-tier mastery."
                )
            return (
                "Review the assessment difficulty profile and consider redistributing question complexity "
                "closer to your syllabus targets."
            )

        elif code == RuleCodes.DIFFICULTY_IMBALANCE:
            actual = ev.get("actual_distribution", {})
            target = ev.get("target_distribution", {})
            return (
                f"Consider re-balancing question complexity closer to your intended targets "
                f"(Target: {target.get('easy', 30):.0f}% Easy / {target.get('medium', 50):.0f}% Med / {target.get('hard', 20):.0f}% Hard; "
                f"Actual: {actual.get('easy', 0):.0f}% Easy / {actual.get('medium', 0):.0f}% Med / {actual.get('hard', 0):.0f}% Hard)."
            )

        elif code == RuleCodes.COGNITIVE_CONCENTRATION:
            level = ev.get("level", "this level")
            pct = ev.get("percentage", 0.0)
            return (
                f"With {pct:.1f}% of evaluation concentrated in '{level}', consider incorporating questions "
                f"from higher cognitive tiers (e.g., Analyze, Evaluate, Create) to stimulate deeper student inquiry."
            )

        elif code == RuleCodes.LOWER_ORDER_CONCENTRATION:
            pct = ev.get("lower_order_percentage", 0.0)
            return (
                f"Lower-order cognitive levels (Remember/Understand) constitute {pct:.1f}% of total evaluation. "
                f"Consider upgrading selected questions to Apply, Analyze, or Design tasks."
            )

        elif code == RuleCodes.LOW_COGNITIVE_DIVERSITY:
            return (
                "Consider varying cognitive challenge levels across questions to measure a wider spectrum of "
                "Bloom's taxonomy domains."
            )

        elif code == RuleCodes.SINGLE_QUESTION_FORMAT:
            q_type = ev.get("question_type", "single format")
            return (
                f"The examination relies entirely on '{q_type}' items. Consider blending multiple formats "
                f"(e.g., Problem Solving, Conceptual Design, Structured Scenarios) to assess diverse student skills."
            )

        elif code == RuleCodes.LOW_QUESTION_DIVERSITY:
            q_type = ev.get("question_type", "this format")
            pct = ev.get("percentage", 0.0)
            return (
                f"'{q_type}' accounts for {pct:.1f}% of the examination. Consider incorporating complementary "
                f"formats to provide varied evaluation modalities."
            )

        elif code == RuleCodes.MARKS_MISMATCH:
            expected = ev.get("expected_total", 0.0)
            actual = ev.get("actual_sum", 0.0)
            disc = ev.get("discrepancy", 0.0)
            return (
                f"Review and calibrate question point values. Total question marks sum to {actual}, "
                f"differing from the expected assessment total of {expected} by {disc:+.1f} marks."
            )

        elif code == RuleCodes.HIGH_MARK_CONCENTRATION:
            pct = ev.get("percentage_of_total", 0.0)
            return (
                f"A single question accounts for {pct:.1f}% of the entire assessment score. Consider breaking this question "
                f"into multi-part sub-questions with graded marks to minimize single-point failure risks."
            )

        elif code == RuleCodes.POTENTIAL_DUPLICATE:
            q_num = ev.get("current_question_number", "?")
            source = ev.get("matched_source", "a previous exam")
            sim = ev.get("similarity_score", 0.0)
            return (
                f"Question #{q_num} has {sim*100:.1f}% semantic similarity with '{source}'. "
                f"Review the question formulation and consider modifying data parameters, constraints, or the operational context."
            )

        elif code == RuleCodes.HIGH_SIMILARITY:
            q_num = ev.get("current_question_number", "?")
            source = ev.get("matched_source", "a previous exam")
            sim = ev.get("similarity_score", 0.0)
            return (
                f"Question #{q_num} exhibits {sim*100:.1f}% similarity with '{source}'. "
                f"Verify whether the question sufficiently tests independent problem-solving skills."
            )

        elif code == RuleCodes.LOW_OVERALL_QUALITY:
            score = ev.get("overall_quality_score", 0.0)
            return (
                f"The overall assessment score is {score:.1f}/100. Review the prioritized high and medium recommendations "
                f"above to improve curriculum balance, LO alignment, and question variety."
            )

        return (
            "Faculty review is recommended to ensure this assessment component aligns with the intended learning objectives."
        )

