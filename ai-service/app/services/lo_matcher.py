import logging
from typing import List, Dict, Any, Optional
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.similarity_service import SimilarityService
from app.services.threshold_bands import classify_alignment, reported_score
from app.schemas.alignment import LearningOutcomeItem, QuestionItem, ThresholdsConfig

logger = logging.getLogger("facultylens.ai")


class LearningOutcomeMatcher:
    """
    Service to match individual questions with defined course learning outcomes
    using dense semantic embeddings and cosine similarity.
    """

    def __init__(self, hf_service: Optional[HuggingFaceService] = None):
        self.hf_service = hf_service or get_hf_service()
        self.similarity_service = SimilarityService()

    def match_questions_to_los(
        self,
        questions: List[QuestionItem],
        learning_outcomes: List[LearningOutcomeItem],
        thresholds: ThresholdsConfig,
        precomputed_q_embeddings: Optional[List[List[float]]] = None,
    ) -> List[Dict[str, Any]]:
        """
        Perform batch semantic encoding of questions and LOs, compute similarity matrix,
        and classify question alignment into STRONG, WEAK, or NOT_ALIGNED.

        Args:
            questions: List of question items to match.
            learning_outcomes: List of learning outcome items.
            thresholds: Similarity thresholds for classification.
            precomputed_q_embeddings: Optional pre-computed question embeddings. When
                provided (and length matches ``questions``), the encoding step is skipped,
                eliminating redundant inference calls in the unified analysis pipeline.
        """
        if not questions or not learning_outcomes:
            return []

        # Prepare text strings for embedding
        # For questions, combine text with any provided topics to enrich semantic context if available
        q_texts = []
        for q in questions:
            if q.topics and len(q.topics) > 0:
                q_texts.append(f"{q.text} (Topics: {', '.join(q.topics)})")
            else:
                q_texts.append(q.text)

        # For LOs, format code and description
        lo_texts = []
        for lo in learning_outcomes:
            if lo.code:
                lo_texts.append(f"{lo.code}: {lo.description}")
            else:
                lo_texts.append(lo.description)

        # Use precomputed embeddings if provided to avoid re-encoding
        if precomputed_q_embeddings is not None and len(precomputed_q_embeddings) == len(questions):
            q_embeddings = precomputed_q_embeddings
        else:
            q_embeddings = self.hf_service.generate_batch_embeddings(q_texts)
        lo_embeddings = self.hf_service.generate_batch_embeddings(lo_texts)

        # Pairwise similarity matrix (N questions x M LOs)
        sim_matrix = self.similarity_service.compute_similarity_matrix(q_embeddings, lo_embeddings)

        results: List[Dict[str, Any]] = []

        for i, q in enumerate(questions):
            q_sims = sim_matrix[i]  # similarities to all M LOs

            # Sort indices by similarity descending
            sorted_indices = q_sims.argsort()[::-1]

            best_idx = int(sorted_indices[0])
            best_sim = reported_score(q_sims[best_idx])
            best_lo = learning_outcomes[best_idx]

            # Categorize best alignment on the score exactly as reported
            status = classify_alignment(best_sim, thresholds.strong, thresholds.weak)

            matched_lo = {
                "id": best_lo.id,
                "code": best_lo.code,
                "description": best_lo.description,
                "similarity": best_sim,
                "alignment_level": status,
            }

            # Find alternative matches (all subsequent LOs with similarity >= weak threshold)
            alt_matches = []
            for alt_idx in sorted_indices[1:]:
                alt_idx_int = int(alt_idx)
                alt_sim = reported_score(q_sims[alt_idx_int])
                alt_lo = learning_outcomes[alt_idx_int]

                alt_level = classify_alignment(alt_sim, thresholds.strong, thresholds.weak)
                if alt_level != "NOT_ALIGNED":
                    alt_matches.append({
                        "id": alt_lo.id,
                        "code": alt_lo.code,
                        "description": alt_lo.description,
                        "similarity": alt_sim,
                        "alignment_level": alt_level,
                    })

            # Formulate explainable reasoning
            lo_label = f"[{best_lo.code}] " if best_lo.code else ""
            if status == "STRONG":
                reasoning = f"Strong semantic alignment ({round(best_sim * 100, 1)}%) with {lo_label}{best_lo.description[:60]}..."
            elif status == "WEAK":
                reasoning = f"Moderate semantic alignment ({round(best_sim * 100, 1)}%) with {lo_label}{best_lo.description[:60]}..."
            else:
                reasoning = f"No strong match found. Highest similarity was only {round(best_sim * 100, 1)}% with {lo_label}{best_lo.description[:60]}..."

            results.append({
                "question_id": q.id,
                "question_number": q.number,
                "question_text": q.text,
                "matched_learning_outcome": matched_lo,
                "alternative_matches": alt_matches,
                "alignment_status": status,
                "similarity_score": round(best_sim, 4),
                "reasoning": reasoning,
            })

        return results

