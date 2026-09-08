import logging
from typing import List, Dict, Any, Optional
from app.config import get_settings
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.similarity_service import SimilarityService
from app.schemas.similarity import (
    CurrentQuestionItem,
    PreviousQuestionItem,
    SimilarityThresholdsConfig,
)

logger = logging.getLogger("facultylens.ai")


class SemanticSimilarityAnalyzer:
    """
    Service for analyzing semantic similarity between current examination questions
    and historical/previous course questions for repetition and duplicate detection.
    """

    def __init__(self, hf_service: Optional[HuggingFaceService] = None):
        self.hf_service = hf_service or get_hf_service()
        self.similarity_service = SimilarityService()

    def analyze(
        self,
        current_questions: List[CurrentQuestionItem],
        previous_questions: List[PreviousQuestionItem],
        thresholds: Optional[SimilarityThresholdsConfig] = None,
        top_k: Optional[int] = None,
        course_id: Optional[int] = None,
        precomputed_current_embeddings: Optional[List[List[float]]] = None,
    ) -> Dict[str, Any]:
        """
        Run batch semantic similarity analysis and duplicate classification.

        Args:
            precomputed_current_embeddings: Optional pre-computed embeddings for current
                questions. When provided, the encode step is skipped to avoid redundant
                inference in the unified analysis pipeline.
        """
        settings = get_settings()

        th_dup  = thresholds.duplicate if thresholds else settings.similarity_duplicate_threshold
        th_high = thresholds.high if thresholds else settings.similarity_high_threshold
        th_mod  = thresholds.moderate if thresholds else settings.similarity_moderate_threshold
        k = top_k or (thresholds.top_k if thresholds else settings.similarity_top_k)

        total_current  = len(current_questions)
        total_previous = len(previous_questions)

        # Edge case: No previous questions available in question bank
        if total_previous == 0:
            results = []
            for idx, cq in enumerate(current_questions):
                results.append({
                    "current_question_id": cq.id,
                    "current_question_number": cq.question_number or (idx + 1),
                    "current_question_text": cq.text,
                    "current_question_type": cq.question_type,
                    "current_cognitive_level": cq.cognitive_level,
                    "max_similarity_score": 0.0,
                    "max_similarity_status": "NOT_SIMILAR",
                    "matches": [],
                    "reasoning": "No previous questions available in course bank for historical comparison.",
                })

            return {
                "status": "success",
                "method": "semantic_embedding_cosine_similarity",
                "model": self.hf_service.model_name,
                "thresholds": {
                    "potential_duplicate": th_dup,
                    "high_similarity": th_high,
                    "moderate_similarity": th_mod,
                },
                "total_current_questions": total_current,
                "total_previous_questions": 0,
                "potential_duplicates_count": 0,
                "highly_similar_count": 0,
                "somewhat_similar_count": 0,
                "average_similarity_score": 0.0,
                "results": results,
                "findings": ["No historical questions found in the course question bank. All questions are novel."],
            }

        # Step 1: Encode Current and Previous Questions
        current_texts  = [q.text.strip() for q in current_questions]
        previous_texts = [q.text.strip() for q in previous_questions]

        # Use precomputed embeddings when available (avoids double-encoding in unified analysis)
        if precomputed_current_embeddings is not None and len(precomputed_current_embeddings) == total_current:
            current_embeddings = precomputed_current_embeddings
        else:
            current_embeddings = self.hf_service.generate_batch_embeddings(current_texts)

        # Encode previous questions — batch for memory efficiency on large banks
        PREV_BATCH_SIZE = 500
        if total_previous > PREV_BATCH_SIZE:
            logger.info(
                f"Large previous question bank ({total_previous}): "
                f"encoding in batches of {PREV_BATCH_SIZE}."
            )
            previous_embeddings: List[List[float]] = []
            for start in range(0, total_previous, PREV_BATCH_SIZE):
                batch_texts = previous_texts[start:start + PREV_BATCH_SIZE]
                batch_vecs  = self.hf_service.generate_batch_embeddings(batch_texts)
                previous_embeddings.extend(batch_vecs)
        else:
            previous_embeddings = self.hf_service.generate_batch_embeddings(previous_texts)

        # Step 2: Compute Pairwise Similarity Matrix (N current x M previous)
        sim_matrix = self.similarity_service.compute_similarity_matrix(
            current_embeddings, previous_embeddings
        )

        results: List[Dict[str, Any]] = []
        potential_dup_count = 0
        highly_similar_count = 0
        somewhat_similar_count = 0
        max_sim_sum = 0.0

        # Step 3: Rank & Classify Matches for each Current Question
        for i, cq in enumerate(current_questions):
            sims = sim_matrix[i]  # vector of length M

            # Sort indices descending by similarity
            sorted_indices = sims.argsort()[::-1]

            # Top-K matches
            top_k_indices = sorted_indices[: min(k, total_previous)]

            matches: List[Dict[str, Any]] = []
            for prev_idx in top_k_indices:
                prev_idx_int = int(prev_idx)
                sim_score = float(sims[prev_idx_int])
                pq = previous_questions[prev_idx_int]

                if sim_score >= th_dup:
                    status = "POTENTIAL_DUPLICATE"
                elif sim_score >= th_high:
                    status = "HIGHLY_SIMILAR"
                elif sim_score >= th_mod:
                    status = "SOMEWHAT_SIMILAR"
                else:
                    status = "NOT_SIMILAR"

                matches.append({
                    "previous_question_id": pq.id,
                    "previous_question_text": pq.text,
                    "similarity_score": round(sim_score, 4),
                    "similarity_status": status,
                    "source_year": pq.source_year,
                    "source_assessment": pq.source_assessment,
                    "question_type": pq.question_type,
                    "cognitive_level": pq.cognitive_level,
                })

            best_sim = float(sims[int(sorted_indices[0])]) if len(sorted_indices) > 0 else 0.0
            best_sim = round(best_sim, 4)
            max_sim_sum += best_sim

            if best_sim >= th_dup:
                max_status = "POTENTIAL_DUPLICATE"
                potential_dup_count += 1
            elif best_sim >= th_high:
                max_status = "HIGHLY_SIMILAR"
                highly_similar_count += 1
            elif best_sim >= th_mod:
                max_status = "SOMEWHAT_SIMILAR"
                somewhat_similar_count += 1
            else:
                max_status = "NOT_SIMILAR"

            # Formulate explainable reasoning
            best_pq = previous_questions[int(sorted_indices[0])] if len(sorted_indices) > 0 else None
            src_info = ""
            if best_pq:
                if best_pq.source_assessment and best_pq.source_year:
                    src_info = f" in {best_pq.source_year} {best_pq.source_assessment}"
                elif best_pq.source_year:
                    src_info = f" in {best_pq.source_year} exam"
                elif best_pq.source_assessment:
                    src_info = f" in {best_pq.source_assessment}"

            q_num_label = f"Q{cq.question_number}" if cq.question_number else "Question"

            if max_status == "POTENTIAL_DUPLICATE":
                reasoning = (
                    f"Potential duplicate detected: {round(best_sim * 100, 1)}% semantic match with historical item{src_info}. "
                    f"Faculty review recommended."
                )
            elif max_status == "HIGHLY_SIMILAR":
                reasoning = (
                    f"High semantic similarity ({round(best_sim * 100, 1)}%) with historical question{src_info}. "
                    f"Verify whether task requirements or datasets differ."
                )
            elif max_status == "SOMEWHAT_SIMILAR":
                reasoning = (
                    f"Moderate thematic overlap ({round(best_sim * 100, 1)}%) with historical question{src_info}."
                )
            else:
                reasoning = (
                    f"Low historical similarity (peak {round(best_sim * 100, 1)}%). Appears to be novel content."
                )

            results.append({
                "current_question_id": cq.id,
                "current_question_number": cq.question_number or (i + 1),
                "current_question_text": cq.text,
                "current_question_type": cq.question_type,
                "current_cognitive_level": cq.cognitive_level,
                "max_similarity_score": best_sim,
                "max_similarity_status": max_status,
                "matches": matches,
                "reasoning": reasoning,
            })

        avg_sim = round((max_sim_sum / total_current) * 100.0, 1) if total_current > 0 else 0.0

        # Step 4: Generate Structured Actionable Findings
        findings: List[str] = []

        if potential_dup_count > 0:
            dup_q_labels = [
                f"Q{r['current_question_number']}" for r in results if r["max_similarity_status"] == "POTENTIAL_DUPLICATE"
            ]
            findings.append(
                f"Potential Duplicates: {potential_dup_count} question(s) ({', '.join(dup_q_labels)}) exhibit >= {round(th_dup * 100)}% semantic similarity with previous exams."
            )

        if highly_similar_count > 0:
            high_q_labels = [
                f"Q{r['current_question_number']}" for r in results if r["max_similarity_status"] == "HIGHLY_SIMILAR"
            ]
            findings.append(
                f"High Repetition Risk: {highly_similar_count} question(s) ({', '.join(high_q_labels)}) share substantial phrasing and concept overlap ({round(th_high * 100)}% - {round(th_dup * 100)}%) with prior assessments."
            )

        novel_count = sum(1 for r in results if r["max_similarity_status"] == "NOT_SIMILAR")
        if novel_count > 0:
            findings.append(
                f"Novel Assessment Items: {novel_count} out of {total_current} questions have low historical similarity (< {round(th_mod * 100)}%), promoting assessment freshness."
            )

        if potential_dup_count == 0 and highly_similar_count == 0:
            findings.append(
                "Assessment Freshness: No potential duplicates or highly repeated questions detected compared to historical course archives."
            )

        return {
            "status": "success",
            "method": "semantic_embedding_cosine_similarity",
            "model": self.hf_service.model_name,
            "thresholds": {
                "potential_duplicate": th_dup,
                "high_similarity": th_high,
                "moderate_similarity": th_mod,
            },
            "total_current_questions": total_current,
            "total_previous_questions": total_previous,
            "potential_duplicates_count": potential_dup_count,
            "highly_similar_count": highly_similar_count,
            "somewhat_similar_count": somewhat_similar_count,
            "average_similarity_score": avg_sim,
            "results": results,
            "findings": findings,
        }

