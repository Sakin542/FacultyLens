import logging
from typing import List, Dict, Any, Optional
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.question_classifier import QuestionClassifier
from app.services.topic_detector import TopicDetector
from app.services.difficulty_analyzer import DifficultyAnalyzer
from app.services.cognitive_analyzer import CognitiveAnalyzer

logger = logging.getLogger("facultylens.ai")


class QuestionAnalyzer:
    """
    Main orchestrator for single and batch academic question analysis.
    Combines question classification, topic detection, difficulty analysis,
    and cognitive level (Bloom's Taxonomy) evaluation.
    """

    def __init__(
        self,
        hf_service: Optional[HuggingFaceService] = None,
        topic_detector: Optional[TopicDetector] = None,
    ):
        self.hf_service = hf_service or get_hf_service()
        self.topic_detector = topic_detector or TopicDetector(hf_service=self.hf_service)

    def analyze_single(
        self,
        question_text: str,
        course_topics: Optional[List[str]] = None,
    ) -> Dict[str, Any]:
        """
        Analyze a single academic question across all 4 analytical dimensions.
        """
        cleaned_text = question_text.strip()
        classification = QuestionClassifier.classify(cleaned_text)
        topics = self.topic_detector.detect_topics(cleaned_text, course_topics=course_topics)
        difficulty = DifficultyAnalyzer.analyze(cleaned_text)
        cognitive_level = CognitiveAnalyzer.analyze(cleaned_text)

        return {
            "status": "success",
            "question": cleaned_text,
            "classification": classification,
            "topics": topics,
            "difficulty": difficulty,
            "cognitive_level": cognitive_level,
        }

    def analyze_batch(
        self,
        questions: List[Dict[str, Any]],
        course_topics: Optional[List[str]] = None,
    ) -> Dict[str, Any]:
        """
        Analyze multiple questions in batch mode with efficient vector batching.
        """
        if not questions:
            return {
                "status": "success",
                "total_questions": 0,
                "questions": [],
                "summary": {
                    "question_types": {},
                    "difficulty_distribution": {},
                    "cognitive_distribution": {},
                    "topics_detected": [],
                },
            }

        question_texts = [q.get("text", "").strip() for q in questions]

        # Batch topic detection
        batch_topics = self.topic_detector.detect_topics_batch(
            question_texts, course_topics=course_topics
        )

        analyzed_questions: List[Dict[str, Any]] = []

        type_counts: Dict[str, int] = {}
        diff_counts: Dict[str, int] = {}
        cog_counts: Dict[str, int] = {}
        all_topics_set = set()

        for idx, q_item in enumerate(questions):
            q_num = q_item.get("number", idx + 1)
            q_text = question_texts[idx]

            classification = QuestionClassifier.classify(q_text)
            topics = batch_topics[idx]
            difficulty = DifficultyAnalyzer.analyze(q_text)
            cognitive_level = CognitiveAnalyzer.analyze(q_text)

            # Accumulate summaries
            q_type = classification["question_type"]
            diff_lvl = difficulty["level"]
            cog_lvl = cognitive_level["level"]

            type_counts[q_type] = type_counts.get(q_type, 0) + 1
            diff_counts[diff_lvl] = diff_counts.get(diff_lvl, 0) + 1
            cog_counts[cog_lvl] = cog_counts.get(cog_lvl, 0) + 1

            for t in topics:
                all_topics_set.add(t["name"])

            analyzed_questions.append({
                "number": q_num,
                "question": q_text,
                "classification": classification,
                "topics": topics,
                "difficulty": difficulty,
                "cognitive_level": cognitive_level,
            })

        return {
            "status": "success",
            "total_questions": len(analyzed_questions),
            "questions": analyzed_questions,
            "summary": {
                "question_types": type_counts,
                "difficulty_distribution": diff_counts,
                "cognitive_distribution": cog_counts,
                "topics_detected": sorted(list(all_topics_set)),
            },
        }

