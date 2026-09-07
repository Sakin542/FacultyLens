import re
import math
from typing import List, Dict, Any, Optional
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.utils.text_utils import extract_basic_keywords


def cosine_similarity(v1: List[float], v2: List[float]) -> float:
    """Calculate cosine similarity between two numeric vectors."""
    if not v1 or not v2 or len(v1) != len(v2):
        return 0.0

    dot = sum(a * b for a, b in zip(v1, v2))
    norm1 = math.sqrt(sum(a * a for a in v1))
    norm2 = math.sqrt(sum(b * b for b in v2))

    if norm1 == 0.0 or norm2 == 0.0:
        return 0.0

    return max(0.0, min(1.0, dot / (norm1 * norm2)))


class TopicDetector:
    """
    Detects relevant academic topics for questions using Hugging Face semantic embeddings
    and candidate course topic matching.
    """

    def __init__(self, hf_service: Optional[HuggingFaceService] = None):
        self.hf_service = hf_service or get_hf_service()

    def detect_topics(
        self,
        question_text: str,
        course_topics: Optional[List[str]] = None,
        threshold: float = 0.25,
        top_k: int = 3,
    ) -> List[Dict[str, Any]]:
        """
        Detect relevant course topics for a single question.
        """
        if not question_text or not question_text.strip():
            return []

        # If candidate course topics are provided, use semantic similarity
        cleaned_topics = [t.strip() for t in (course_topics or []) if t and t.strip()]

        if cleaned_topics:
            try:
                # 1. Generate question embedding
                q_vector = self.hf_service.generate_embedding(question_text)

                # 2. Generate embeddings for all course topics
                topic_vectors = self.hf_service.generate_batch_embeddings(cleaned_topics)

                # 3. Calculate similarities
                scored_topics = []
                for topic_name, t_vector in zip(cleaned_topics, topic_vectors):
                    sim = cosine_similarity(q_vector, t_vector)
                    if sim >= threshold:
                        scored_topics.append({
                            "name": topic_name,
                            "confidence": round(float(sim), 2),
                        })

                # Sort descending by confidence
                scored_topics.sort(key=lambda x: x["confidence"], reverse=True)
                if scored_topics:
                    return scored_topics[:top_k]

                # Fallback: if none met threshold, return highest matching topic if > 0.20
                if cleaned_topics:
                    all_scored = [
                        {
                            "name": t,
                            "confidence": round(float(cosine_similarity(q_vector, tv)), 2),
                        }
                        for t, tv in zip(cleaned_topics, topic_vectors)
                    ]
                    all_scored.sort(key=lambda x: x["confidence"], reverse=True)
                    if all_scored and all_scored[0]["confidence"] >= 0.20:
                        return all_scored[:1]

            except Exception:
                # Fallback to lexical matching if model inference fails
                pass

        # NLP keyword/phrase extraction fallback if no course topics or embedding unavailable
        return self._extract_heuristic_topics(question_text, top_k=top_k)

    def detect_topics_batch(
        self,
        questions: List[str],
        course_topics: Optional[List[str]] = None,
        threshold: float = 0.25,
        top_k: int = 3,
    ) -> List[List[Dict[str, Any]]]:
        """
        Detect topics for a batch of questions efficiently by pre-computing topic embeddings once.
        """
        if not questions:
            return []

        cleaned_topics = [t.strip() for t in (course_topics or []) if t and t.strip()]

        if cleaned_topics:
            try:
                # Precompute topic embeddings once for the entire batch
                topic_vectors = self.hf_service.generate_batch_embeddings(cleaned_topics)

                # Compute question embeddings in one batch
                q_vectors = self.hf_service.generate_batch_embeddings(questions)

                batch_results = []
                for q_text, q_vec in zip(questions, q_vectors):
                    scored = []
                    for topic_name, t_vec in zip(cleaned_topics, topic_vectors):
                        sim = cosine_similarity(q_vec, t_vec)
                        if sim >= threshold:
                            scored.append({
                                "name": topic_name,
                                "confidence": round(float(sim), 2),
                            })
                    scored.sort(key=lambda x: x["confidence"], reverse=True)
                    if not scored and cleaned_topics:
                        # Fallback to highest if >= 0.20
                        all_s = [
                            {"name": t, "confidence": round(float(cosine_similarity(q_vec, tv)), 2)}
                            for t, tv in zip(cleaned_topics, topic_vectors)
                        ]
                        all_s.sort(key=lambda x: x["confidence"], reverse=True)
                        if all_s and all_s[0]["confidence"] >= 0.20:
                            scored = all_s[:1]

                    batch_results.append(scored[:top_k] if scored else self._extract_heuristic_topics(q_text, top_k=top_k))

                return batch_results
            except Exception:
                pass

        # Fallback for each question individually
        return [self._extract_heuristic_topics(q, top_k=top_k) for q in questions]

    def _extract_heuristic_topics(self, text: str, top_k: int = 2) -> List[Dict[str, Any]]:
        """
        Extract candidate domain keywords/phrases from text when no topics are supplied.
        """
        keywords = extract_basic_keywords(text, top_k=top_k)
        return [
            {
                "name": kw.title(),
                "confidence": 0.70,
            }
            for kw in keywords
        ]
