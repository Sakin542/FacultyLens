"""Problem Detector service for FacultyLens AI Recommendation Engine.

Inspects all prior evaluation results (topics, LOs, difficulty, cognitive tiers,
question diversity, marks integrity, and past examination similarity) and produces
deterministic, evidence-backed problem records.
"""

from typing import List, Dict, Any, Optional
from app.schemas.recommendation import (
    DetectedProblem,
    ProblemCategory,
    RecommendationPriority,
    RecommendationRequest,
    TopicAnalysisInput,
    LoAnalysisInput,
    DifficultyAnalysisInput,
    CognitiveAnalysisInput,
    QuestionDiversityInput,
    MarksAnalysisInput,
    SimilarityAnalysisInput,
    QualityAnalysisInput,
)
from app.services.recommendation_rules import DEFAULT_THRESHOLDS, RuleCodes


class ProblemDetector:
    """Detects academic assessment problems and compiles structured evidence."""

    def __init__(self, custom_thresholds: Optional[Dict[str, Any]] = None):
        self.thresholds = dict(DEFAULT_THRESHOLDS)
        if custom_thresholds:
            self.thresholds.update(custom_thresholds)

    def detect_problems(self, request: RecommendationRequest) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []

        if request.topic_analysis:
            problems.extend(self._detect_topic_problems(request.topic_analysis))

        if request.learning_outcome_analysis:
            problems.extend(self._detect_lo_problems(request.learning_outcome_analysis))

        if request.difficulty_analysis:
            problems.extend(self._detect_difficulty_problems(request.difficulty_analysis))

        if request.cognitive_analysis:
            problems.extend(self._detect_cognitive_problems(request.cognitive_analysis))

        if request.question_diversity_analysis:
            problems.extend(self._detect_question_diversity_problems(request.question_diversity_analysis))

        if request.marks_analysis:
            problems.extend(self._detect_marks_problems(request.marks_analysis))

        if request.similarity_analysis:
            problems.extend(self._detect_similarity_problems(request.similarity_analysis))

        if request.quality_analysis:
            problems.extend(self._detect_quality_problems(request.quality_analysis))

        return problems

    def _detect_topic_problems(self, data: TopicAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE" or not data.topics:
            return problems

        for topic in data.topics:
            name = topic.get("name") or topic.get("topic") or "Unknown Topic"
            status = str(topic.get("status") or "").upper()
            q_count = int(topic.get("question_count") or 0)
            cov_pct = float(topic.get("coverage_percentage") or topic.get("coverage_percent") or 0.0)

            if status == "NOT_COVERED" or q_count == 0 or cov_pct == 0.0:
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.TOPIC_NOT_COVERED,
                        category=ProblemCategory.TOPIC_COVERAGE,
                        title=f"Syllabus Topic '{name}' is not represented",
                        explanation=f"The examination contains no questions mapped to the topic '{name}'.",
                        evidence={
                            "topic_name": name,
                            "question_count": q_count,
                            "coverage_percentage": cov_pct,
                            "status": "NOT_COVERED",
                        },
                        source_metric="Topic Coverage Analysis",
                        severity=RecommendationPriority.HIGH if (data.total_topics or 0) <= 4 else RecommendationPriority.MEDIUM,
                    )
                )
            elif status == "LOW" or (0 < cov_pct < self.thresholds["topic_low_coverage_percent"]):
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.TOPIC_LOW_COVERAGE,
                        category=ProblemCategory.TOPIC_COVERAGE,
                        title=f"Topic '{name}' has limited assessment coverage",
                        explanation=f"Topic '{name}' is evaluated with low coverage ({cov_pct:.1f}%).",
                        evidence={
                            "topic_name": name,
                            "question_count": q_count,
                            "coverage_percentage": cov_pct,
                            "status": "LOW",
                        },
                        source_metric="Topic Coverage Analysis",
                        severity=RecommendationPriority.MEDIUM,
                    )
                )

        return problems

    def _detect_lo_problems(self, data: LoAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE" or not data.learning_outcomes:
            return problems

        for lo in data.learning_outcomes:
            code = lo.get("code") or lo.get("lo_code") or "LO"
            desc = lo.get("description") or ""
            status = str(lo.get("status") or lo.get("coverage_status") or "").upper()
            q_count = int(lo.get("question_count") or lo.get("matching_questions_count") or 0)
            strong_count = int(lo.get("strong_matches_count") or 0)
            weak_count = int(lo.get("weak_matches_count") or 0)
            max_sim = float(lo.get("max_similarity") or 0.0)

            if status == "NOT_COVERED" or (q_count == 0 and max_sim < self.thresholds["lo_weak_similarity_threshold"]):
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.LO_NOT_COVERED,
                        category=ProblemCategory.LEARNING_OUTCOME,
                        title=f"{code} has no aligned examination questions",
                        explanation=f"None of the examination questions demonstrate sufficient semantic alignment with {code}.",
                        evidence={
                            "lo_code": code,
                            "description": desc,
                            "aligned_questions_count": q_count,
                            "strong_matches_count": strong_count,
                            "weak_matches_count": weak_count,
                            "max_similarity_score": round(max_sim, 3),
                        },
                        source_metric="Learning Outcome Alignment",
                        severity=RecommendationPriority.HIGH,
                    )
                )
            elif status in ("WEAK", "WEAKLY_COVERED") or (strong_count == 0 and (weak_count > 0 or max_sim >= self.thresholds["lo_weak_similarity_threshold"])):
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.LO_WEAKLY_COVERED,
                        category=ProblemCategory.LEARNING_OUTCOME,
                        title=f"{code} is only weakly assessed",
                        explanation=f"{code} does not have any strongly aligned questions and relies on partial/weak matches.",
                        evidence={
                            "lo_code": code,
                            "description": desc,
                            "aligned_questions_count": q_count,
                            "strong_matches_count": strong_count,
                            "weak_matches_count": weak_count,
                            "max_similarity_score": round(max_sim, 3),
                        },
                        source_metric="Learning Outcome Alignment",
                        severity=RecommendationPriority.MEDIUM,
                    )
                )

        return problems

    def _detect_difficulty_problems(self, data: DifficultyAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE":
            return problems

        easy_pct = float(data.easy_percentage or 0.0)
        med_pct = float(data.medium_percentage or 0.0)
        hard_pct = float(data.hard_percentage or 0.0)
        tot_dev = float(data.total_deviation or 0.0)

        # Extreme skew check
        if easy_pct == 0.0:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.DIFFICULTY_EXTREME_SKEW,
                    category=ProblemCategory.DIFFICULTY,
                    title="No foundational (Easy) difficulty questions found",
                    explanation="The assessment contains 0% introductory/easy questions, which may create a steep entry barrier for basic competency verification.",
                    evidence={"easy_percentage": 0.0, "medium_percentage": med_pct, "hard_percentage": hard_pct, "target_easy_percentage": data.target_easy_percentage},
                    source_metric="Difficulty Balance Analysis",
                    severity=RecommendationPriority.MEDIUM,
                )
            )
        elif hard_pct == 0.0:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.DIFFICULTY_EXTREME_SKEW,
                    category=ProblemCategory.DIFFICULTY,
                    title="No advanced (Hard) difficulty questions found",
                    explanation="The assessment lacks rigorous challenge questions to distinguish high-performing students.",
                    evidence={"easy_percentage": easy_pct, "medium_percentage": med_pct, "hard_percentage": 0.0, "target_hard_percentage": data.target_hard_percentage},
                    source_metric="Difficulty Balance Analysis",
                    severity=RecommendationPriority.MEDIUM,
                )
            )
        elif tot_dev > self.thresholds["difficulty_max_deviation_warn"]:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.DIFFICULTY_IMBALANCE,
                    category=ProblemCategory.DIFFICULTY,
                    title="Substantial deviation from difficulty targets",
                    explanation=f"The difficulty distribution deviates by {tot_dev:.1f}% from the target profile ({data.target_easy_percentage:.0f}% Easy / {data.target_medium_percentage:.0f}% Med / {data.target_hard_percentage:.0f}% Hard).",
                    evidence={
                        "actual_distribution": {"easy": easy_pct, "medium": med_pct, "hard": hard_pct},
                        "target_distribution": {"easy": data.target_easy_percentage, "medium": data.target_medium_percentage, "hard": data.target_hard_percentage},
                        "total_deviation": round(tot_dev, 2),
                    },
                    source_metric="Difficulty Balance Analysis",
                    severity=RecommendationPriority.MEDIUM,
                )
            )

        return problems

    def _detect_cognitive_problems(self, data: CognitiveAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE":
            return problems

        # Check for heavy single Bloom tier concentration
        if data.distribution:
            for item in data.distribution:
                tier = item.get("level") or item.get("cognitive_level") or "Unknown"
                pct = float(item.get("percentage") or item.get("marks_percentage") or 0.0)
                if pct >= self.thresholds["cognitive_concentration_warn_percent"]:
                    problems.append(
                        DetectedProblem(
                            code=RuleCodes.COGNITIVE_CONCENTRATION,
                            category=ProblemCategory.COGNITIVE_LEVEL,
                            title=f"Cognitive concentration at '{tier}' level ({pct:.1f}%)",
                            explanation=f"A large proportion of questions/marks ({pct:.1f}%) is concentrated at the '{tier}' Bloom taxonomy level.",
                            evidence={"level": tier, "percentage": round(pct, 1), "threshold": self.thresholds["cognitive_concentration_warn_percent"]},
                            source_metric="Bloom Cognitive Distribution",
                            severity=RecommendationPriority.MEDIUM,
                        )
                    )

        # Check for lower-order dominance
        lower_pct = float(data.lower_order_percentage or 0.0)
        if lower_pct >= self.thresholds["cognitive_lower_order_warn_percent"]:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.LOWER_ORDER_CONCENTRATION,
                    category=ProblemCategory.COGNITIVE_LEVEL,
                    title=f"Heavy reliance on Lower-Order cognitive tasks ({lower_pct:.1f}%)",
                    explanation=f"Remember and Understand level questions constitute {lower_pct:.1f}% of the evaluation.",
                    evidence={"lower_order_percentage": round(lower_pct, 1), "threshold": self.thresholds["cognitive_lower_order_warn_percent"]},
                    source_metric="Bloom Cognitive Distribution",
                    severity=RecommendationPriority.MEDIUM,
                )
            )
        elif float(data.diversity_score or data.normalized_entropy or 100.0) < self.thresholds["cognitive_min_entropy_score"]:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.LOW_COGNITIVE_DIVERSITY,
                    category=ProblemCategory.COGNITIVE_LEVEL,
                    title="Low cognitive diversity across Bloom's Taxonomy",
                    explanation="Questions are narrowly clustered in few cognitive levels rather than spanning across analytical or evaluative dimensions.",
                    evidence={"diversity_score": round(float(data.diversity_score or data.normalized_entropy or 0.0), 1)},
                    source_metric="Bloom Cognitive Distribution",
                    severity=RecommendationPriority.LOW,
                )
            )

        return problems

    def _detect_question_diversity_problems(self, data: QuestionDiversityInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE" or not data.distribution:
            return problems

        # Check if single format accounts for >= 80% or 100%
        for item in data.distribution:
            q_type = item.get("type") or item.get("question_type") or "Unknown"
            pct = float(item.get("percentage") or 0.0)
            if pct >= 99.0:
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.SINGLE_QUESTION_FORMAT,
                        category=ProblemCategory.QUESTION_DIVERSITY,
                        title=f"All questions are single format ('{q_type}')",
                        explanation=f"The entire examination utilizes only '{q_type}' questions without format diversity.",
                        evidence={"question_type": q_type, "percentage": round(pct, 1)},
                        source_metric="Question Format Diversity",
                        severity=RecommendationPriority.MEDIUM,
                    )
                )
            elif pct >= self.thresholds["question_format_single_type_warn_percent"]:
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.LOW_QUESTION_DIVERSITY,
                        category=ProblemCategory.QUESTION_DIVERSITY,
                        title=f"High reliance on '{q_type}' question format ({pct:.1f}%)",
                        explanation=f"The assessment predominantly ({pct:.1f}%) uses '{q_type}' questions.",
                        evidence={"question_type": q_type, "percentage": round(pct, 1)},
                        source_metric="Question Format Diversity",
                        severity=RecommendationPriority.LOW,
                    )
                )

        return problems

    def _detect_marks_problems(self, data: MarksAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE":
            return problems

        # Marks sum mismatch check
        if data.marks_match is False or abs(float(data.discrepancy or 0.0)) > 0.01:
            disc = float(data.discrepancy or 0.0)
            problems.append(
                DetectedProblem(
                    code=RuleCodes.MARKS_MISMATCH,
                    category=ProblemCategory.MARKS_DISTRIBUTION,
                    title="Total question marks do not match assessment total",
                    explanation=f"Sum of question marks ({data.total_marks_actual}) differs from expected assessment total ({data.total_marks_expected}) by {disc:+.1f} marks.",
                    evidence={
                        "expected_total": data.total_marks_expected,
                        "actual_sum": data.total_marks_actual,
                        "discrepancy": round(disc, 2),
                    },
                    source_metric="Marks Integrity Check",
                    severity=RecommendationPriority.HIGH,
                )
            )

        # High mark concentration
        max_pct = float(data.max_single_question_percentage or 0.0)
        if max_pct >= self.thresholds["marks_single_question_high_concentration"]:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.HIGH_MARK_CONCENTRATION,
                    category=ProblemCategory.MARKS_DISTRIBUTION,
                    title=f"Extreme mark concentration in a single question ({max_pct:.1f}%)",
                    explanation=f"A single question contributes {max_pct:.1f}% of the total examination score ({data.max_single_question_marks} marks), creating a high-stakes bottleneck.",
                    evidence={
                        "max_single_question_marks": data.max_single_question_marks,
                        "percentage_of_total": round(max_pct, 1),
                        "threshold": self.thresholds["marks_single_question_high_concentration"],
                    },
                    source_metric="Marks Distribution Analysis",
                    severity=RecommendationPriority.HIGH if max_pct >= 45.0 else RecommendationPriority.MEDIUM,
                )
            )

        return problems

    def _detect_similarity_problems(self, data: SimilarityAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.status == "UNAVAILABLE":
            return problems

        items = data.matches or data.question_similarities or []
        for item in items:
            q_num = item.get("current_question_number") or item.get("question_number") or "?"
            q_text = item.get("current_question_text") or item.get("question_text") or ""
            max_sim = float(item.get("max_similarity_score") or item.get("similarity_score") or 0.0)
            status = str(item.get("max_similarity_status") or item.get("similarity_status") or "").upper()
            sub_matches = item.get("matches") or []

            top_source = "Past Assessment"
            if sub_matches:
                top_source = sub_matches[0].get("source_assessment") or sub_matches[0].get("previous_question_text", "Past Question")

            if status == "POTENTIAL_DUPLICATE" or max_sim >= self.thresholds["similarity_duplicate_threshold"]:
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.POTENTIAL_DUPLICATE,
                        category=ProblemCategory.SEMANTIC_SIMILARITY,
                        title=f"Question #{q_num} is potentially duplicated from previous exam ({max_sim*100:.1f}%)",
                        explanation=f"Question #{q_num} shares {max_sim*100:.1f}% semantic similarity with a question from '{top_source}'.",
                        evidence={
                            "current_question_number": q_num,
                            "current_question_text": q_text[:120] + ("..." if len(q_text) > 120 else ""),
                            "similarity_score": round(max_sim, 3),
                            "matched_source": top_source,
                            "status": "POTENTIAL_DUPLICATE",
                        },
                        source_metric="Semantic Similarity Engine",
                        severity=RecommendationPriority.HIGH,
                    )
                )
            elif status == "HIGHLY_SIMILAR" or (max_sim >= self.thresholds["similarity_high_threshold"]):
                problems.append(
                    DetectedProblem(
                        code=RuleCodes.HIGH_SIMILARITY,
                        category=ProblemCategory.SEMANTIC_SIMILARITY,
                        title=f"Question #{q_num} has high similarity with past exam ({max_sim*100:.1f}%)",
                        explanation=f"Question #{q_num} shares substantial conceptual formulation ({max_sim*100:.1f}%) with a question from '{top_source}'.",
                        evidence={
                            "current_question_number": q_num,
                            "current_question_text": q_text[:120] + ("..." if len(q_text) > 120 else ""),
                            "similarity_score": round(max_sim, 3),
                            "matched_source": top_source,
                            "status": "HIGHLY_SIMILAR",
                        },
                        source_metric="Semantic Similarity Engine",
                        severity=RecommendationPriority.MEDIUM,
                    )
                )

        return problems

    def _detect_quality_problems(self, data: QualityAnalysisInput) -> List[DetectedProblem]:
        problems: List[DetectedProblem] = []
        if data.overall_quality_score is None:
            return problems

        score = float(data.overall_quality_score)
        if score < self.thresholds["quality_critical_score"]:
            problems.append(
                DetectedProblem(
                    code=RuleCodes.LOW_OVERALL_QUALITY,
                    category=ProblemCategory.ASSESSMENT_QUALITY,
                    title=f"Overall assessment quality index is low ({score:.1f}/100)",
                    explanation="Multiple pedagogical dimensions are currently imbalanced or unfulfilled.",
                    evidence={"overall_quality_score": round(score, 1), "rating": data.rating or "NEEDS_REVIEW"},
                    source_metric="Assessment Quality Engine",
                    severity=RecommendationPriority.HIGH,
                )
            )

        return problems

