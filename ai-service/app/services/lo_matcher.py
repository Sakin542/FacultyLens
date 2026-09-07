import logging
from typing import List, Dict, Any, Optional
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.similarity_service import SimilarityService
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
    ) -> List[Dict[str, Any]]:
        """
        Perform batch semantic encoding of questions and LOs, compute similarity matrix,
        and classify question alignment into STRONG, WEAK, or NOT_ALIGNED.
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

        # Batch encode
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
            best_sim = float(q_sims[best_idx])
            best_lo = learning_outcomes[best_idx]

            # Categorize best alignment
            if best_sim >= thresholds.strong:
                status = "STRONG"
            elif best_sim >= thresholds.weak:
                status = "WEAK"
            else:
                status = "NOT_ALIGNED"

            matched_lo = {
                "id": best_lo.id,
                "code": best_lo.code,
                "description": best_lo.description,
                "similarity": round(best_sim, 4),
                "alignment_level": status,
            }

            # Find alternative matches (all subsequent LOs with similarity >= weak threshold)
            alt_matches = []
            for alt_idx in sorted_indices[1:]:
                alt_idx_int = int(alt_idx)
                alt_sim = float(q_sims[alt_idx_int])
                alt_lo = learning_outcomes[alt_idx_int]

                if alt_sim >= thresholds.weak:
                    alt_level = "STRONG" if alt_sim >= thresholds.strong else "WEAK"
                    alt_matches.append({
                        "id": alt_lo.id,
                        "code": alt_lo.code,
                        "description": alt_lo.description,
                        "similarity": round(alt_sim, 4),
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

