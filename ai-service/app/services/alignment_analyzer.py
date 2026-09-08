import logging
from typing import List, Dict, Any, Optional
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.lo_matcher import LearningOutcomeMatcher
from app.schemas.alignment import (
    LearningOutcomeItem,
    QuestionItem,
    ThresholdsConfig,
)

logger = logging.getLogger("facultylens.ai")


class AlignmentAnalyzer:
    """
    Orchestrator for Learning Outcome Alignment Analysis.
    Calculates Question -> LO matching, LO Coverage, Overall Alignment Score,
    and structured explainable findings for faculty decision support.
    """

    def __init__(self, hf_service: Optional[HuggingFaceService] = None):
        self.hf_service = hf_service or get_hf_service()
        self.matcher = LearningOutcomeMatcher(hf_service=self.hf_service)

    def analyze(
        self,
        questions: List[QuestionItem],
        learning_outcomes: List[LearningOutcomeItem],
        thresholds: Optional[ThresholdsConfig] = None,
        course_id: Optional[int] = None,
        precomputed_q_embeddings: Optional[List[List[float]]] = None,
    ) -> Dict[str, Any]:
        """
        Run the complete LO alignment analysis pipeline.

        Args:
            precomputed_q_embeddings: Optional pre-computed question embeddings from the
                unified analysis pipeline, avoiding redundant inference calls.
        """
        th = thresholds or ThresholdsConfig()

        # Step 1: Match questions to LOs (pass precomputed embeddings when available)
        question_alignments = self.matcher.match_questions_to_los(
            questions=questions,
            learning_outcomes=learning_outcomes,
            thresholds=th,
            precomputed_q_embeddings=precomputed_q_embeddings,
        )

        total_questions = len(questions)
        total_los = len(learning_outcomes)

        # Step 2: Compute Learning Outcome Coverage
        # Initialize map for each LO
        lo_stats: Dict[str, Dict[str, Any]] = {}
        for idx, lo in enumerate(learning_outcomes):
            key = str(lo.id) if lo.id is not None else f"lo_idx_{idx}"
            lo_stats[key] = {
                "learning_outcome_id": lo.id,
                "code": lo.code,
                "description": lo.description,
                "matching_questions": [],
                "max_similarity": 0.0,
                "strong_count": 0,
                "weak_count": 0,
            }

        # Accumulate questions matching each LO
        for qa in question_alignments:
            matched_lo = qa.get("matched_learning_outcome")
            if not matched_lo:
                continue

            sim = matched_lo.get("similarity", 0.0)
            status = qa.get("alignment_status", "NOT_ALIGNED")
            q_num = qa.get("question_number") or qa.get("question_id") or "Unknown"

            # Find matching LO bucket by ID or code/description
            for key, stat in lo_stats.items():
                is_match = False
                if matched_lo.get("id") is not None and stat["learning_outcome_id"] == matched_lo["id"]:
                    is_match = True
                elif matched_lo.get("code") and stat["code"] == matched_lo["code"]:
                    is_match = True
                elif stat["description"] == matched_lo["description"]:
                    is_match = True

                if is_match:
                    if sim > stat["max_similarity"]:
                        stat["max_similarity"] = sim
                    if status in ["STRONG", "WEAK"]:
                        stat["matching_questions"].append(q_num)
                        if status == "STRONG":
                            stat["strong_count"] += 1
                        else:
                            stat["weak_count"] += 1

        # Format LO coverage list
        lo_coverage_results = []
        covered_lo_count = 0
        weakly_covered_lo_count = 0
        uncovered_lo_count = 0

        for key, stat in lo_stats.items():
            max_sim = stat["max_similarity"]
            if stat["strong_count"] > 0 or max_sim >= th.strong:
                coverage_status = "COVERED"
                covered_lo_count += 1
            elif stat["weak_count"] > 0 or max_sim >= th.weak:
                coverage_status = "WEAKLY_COVERED"
                weakly_covered_lo_count += 1
            else:
                coverage_status = "NOT_COVERED"
                uncovered_lo_count += 1

            lo_coverage_results.append({
                "learning_outcome_id": stat["learning_outcome_id"],
                "code": stat["code"],
                "description": stat["description"],
                "coverage_status": coverage_status,
                "matching_questions_count": len(stat["matching_questions"]),
                "matching_question_numbers": stat["matching_questions"],
                "max_similarity": round(max_sim, 4),
            })

        # Step 3: Calculate Overall Alignment Score
        # Formula: (sum(strong * 1.0 + weak * 0.5 + not_aligned * 0.0) / total_questions) * 100
        strong_q_count = sum(1 for q in question_alignments if q["alignment_status"] == "STRONG")
        weak_q_count = sum(1 for q in question_alignments if q["alignment_status"] == "WEAK")
        unaligned_q_count = sum(1 for q in question_alignments if q["alignment_status"] == "NOT_ALIGNED")

        if total_questions > 0:
            raw_score = ((strong_q_count * 1.0 + weak_q_count * 0.5) / total_questions) * 100.0
            overall_alignment_score = round(raw_score, 1)
        else:
            overall_alignment_score = 0.0

        aligned_questions_count = strong_q_count + weak_q_count

        # Step 4: Generate Structured Explainable Findings
        findings: List[str] = []

        # Finding: Uncovered LOs
        uncovered_los = [lo for lo in lo_coverage_results if lo["coverage_status"] == "NOT_COVERED"]
        if uncovered_los:
            codes = [lo["code"] if lo["code"] else lo["description"][:30] for lo in uncovered_los]
            findings.append(
                f"Unassessed Learning Outcomes: {', '.join(codes)} currently have no matching exam questions (similarity < {th.weak})."
            )

        # Finding: Weakly covered LOs
        weak_los = [lo for lo in lo_coverage_results if lo["coverage_status"] == "WEAKLY_COVERED"]
        if weak_los:
            codes = [lo["code"] if lo["code"] else lo["description"][:30] for lo in weak_los]
            findings.append(
                f"Marginal Coverage: {', '.join(codes)} are only weakly represented by assessment questions."
            )

        # Finding: Unaligned Questions
        unaligned_questions = [q for q in question_alignments if q["alignment_status"] == "NOT_ALIGNED"]
        if unaligned_questions:
            q_labels = [f"Q{q['question_number']}" if q['question_number'] else "a question" for q in unaligned_questions]
            findings.append(
                f"{len(unaligned_questions)} question(s) ({', '.join(q_labels[:5])}{'...' if len(q_labels) > 5 else ''}) did not clearly align with any defined course learning outcomes."
            )

        # Finding: Overrepresentation (e.g., >50% of questions targeting 1 LO when there are 3+ LOs)
        if total_los >= 3 and total_questions >= 3:
            for lo in lo_coverage_results:
                if lo["matching_questions_count"] / total_questions >= 0.50:
                    lo_name = lo["code"] or lo["description"][:30]
                    findings.append(
                        f"Imbalanced Assessment: {round((lo['matching_questions_count'] / total_questions) * 100)}% of questions map to {lo_name}, indicating potential over-concentration."
                    )

        # Finding: Positive reinforcement if coverage is high
        if uncovered_lo_count == 0 and weak_los == [] and overall_alignment_score >= 80.0:
            findings.append("Excellent alignment: All defined learning outcomes are strongly covered across the exam questions.")
        elif uncovered_lo_count == 0 and overall_alignment_score >= 70.0:
            findings.append("Good overall coverage: All defined course learning outcomes are represented in the assessment.")

        return {
            "status": "success",
            "method": "sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity",
            "overall_alignment_score": overall_alignment_score,
            "aligned_questions_count": aligned_questions_count,
            "total_questions": total_questions,
            "total_learning_outcomes": total_los,
            "covered_learning_outcomes_count": covered_lo_count + weakly_covered_lo_count,
            "question_alignment": question_alignments,
            "learning_outcome_coverage": lo_coverage_results,
            "findings": findings,
            "thresholds": {
                "strong": th.strong,
                "weak": th.weak,
            },
        }
