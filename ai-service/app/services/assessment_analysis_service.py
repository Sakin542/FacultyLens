"""Unified Assessment Analysis Service for FacultyLens.

Orchestrates the entire AI analysis workflow across:
1. Question Analysis (Bloom taxonomy, difficulty, question format, syllabus topics)
2. Learning Outcome Alignment (dense semantic embeddings, cosine similarity, coverage)
3. Semantic Similarity (historical question bank duplicate/similarity detection)
4. Assessment Quality Engine (6 pedagogical dimensions, entropy, quality score)
5. AI Recommendation Engine (evidence-based recommendations for faculty decision support)
"""

import logging
from typing import Dict, Any, List, Optional
from app.services.huggingface_service import HuggingFaceService
from app.services.question_analyzer import QuestionAnalyzer
from app.services.alignment_analyzer import AlignmentAnalyzer
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from app.services.assessment_quality_engine import AssessmentQualityEngine
from app.services.recommendation_engine import RecommendationEngine

from app.schemas.assessment_analysis import (
    UnifiedAssessmentAnalysisRequest,
    UnifiedAssessmentAnalysisResponse,
)
from app.schemas.alignment import (
    QuestionItem as AlignmentQuestionItem,
    LearningOutcomeItem as AlignmentLOItem,
)
from app.schemas.similarity import (
    CurrentQuestionItem,
    PreviousQuestionItem,
)
from app.schemas.quality import (
    QualityAnalysisRequest,
    AssessmentMetadataInput,
    AssessmentQuestionInput,
    TopicInput,
    LearningOutcomeInput,
    QualityWeightsConfig,
    DifficultyTargetsConfig,
)
from app.schemas.recommendation import (
    RecommendationRequest,
    AssessmentMeta,
    TopicAnalysisInput,
    LoAnalysisInput,
    DifficultyAnalysisInput,
    CognitiveAnalysisInput,
    QuestionDiversityInput,
    MarksAnalysisInput,
    SimilarityAnalysisInput,
    QualityAnalysisInput,
)

logger = logging.getLogger("facultylens.ai")


def _extract_topic_names(topics_raw: Any) -> List[str]:
    if not topics_raw:
        return []
    result = []
    for t in topics_raw:
        if isinstance(t, dict):
            name = t.get("name")
            if name:
                result.append(str(name))
        elif isinstance(t, str) and t.strip():
            result.append(t.strip())
    return result


class AssessmentAnalysisService:
    """Consolidated orchestrator for end-to-end examination AI analysis."""

    def __init__(self, hf_service: HuggingFaceService):
        self.hf_service = hf_service
        self.question_analyzer = QuestionAnalyzer(hf_service=hf_service)
        self.alignment_analyzer = AlignmentAnalyzer(hf_service=hf_service)
        self.similarity_analyzer = SemanticSimilarityAnalyzer(hf_service=hf_service)
        self.quality_engine = AssessmentQualityEngine()

    def analyze_assessment(
        self, request: UnifiedAssessmentAnalysisRequest
    ) -> Dict[str, Any]:
        """Execute the complete unified analysis pipeline."""
        logger.info(
            f"Starting unified assessment analysis: {len(request.questions)} questions, "
            f"{len(request.learning_outcomes or [])} LOs, "
            f"{len(request.course_topics or [])} topics, "
            f"{len(request.previous_questions or [])} previous questions."
        )

        # ------------------------------------------------------------------
        # STEP 1: Question Analysis (Bloom's, Difficulty, Format, Topics)
        # ------------------------------------------------------------------
        q_dicts = [
            {
                "id": q.id,
                "number": q.number,
                "text": q.text,
                "marks": q.marks or 1.0,
            }
            for q in request.questions
        ]
        batch_question_analysis = self.question_analyzer.analyze_batch(
            questions=q_dicts,
            course_topics=request.course_topics or [],
        )
        analyzed_questions_list = batch_question_analysis.get("questions", [])

        # Create lookup for enriched question properties
        enriched_q_map = {
            q_res.get("number", i + 1): q_res
            for i, q_res in enumerate(analyzed_questions_list)
        }

        # ------------------------------------------------------------------
        # STEP 2: Learning Outcome Alignment
        # ------------------------------------------------------------------
        alignment_result: Optional[Dict[str, Any]] = None
        q_lo_match_map: Dict[Any, Dict[str, Any]] = {}

        if request.learning_outcomes and len(request.learning_outcomes) > 0:
            try:
                align_q_items = [
                    AlignmentQuestionItem(
                        id=q.id,
                        number=q.number,
                        text=q.text,
                        topics=_extract_topic_names(
                            enriched_q_map.get(q.number, {}).get("topics", [])
                            or q.topics
                            or []
                        ),
                    )
                    for q in request.questions
                ]
                align_lo_items = [
                    AlignmentLOItem(
                        id=lo.id,
                        code=lo.code,
                        description=lo.description,
                    )
                    for lo in request.learning_outcomes
                ]
                alignment_result = self.alignment_analyzer.analyze(
                    questions=align_q_items,
                    learning_outcomes=align_lo_items,
                    course_id=request.course_id,
                )

                # Map question number/id -> matched LO code
                align_items = alignment_result.get("question_alignment") or alignment_result.get("questions_alignment", [])
                for q_align in align_items:
                    q_num = q_align.get("question_number")
                    matched_lo = q_align.get("matched_learning_outcome")
                    if matched_lo and matched_lo.get("code"):
                        q_lo_match_map[q_num] = matched_lo
            except Exception as e:
                logger.error(f"Error during LO alignment in unified analysis: {e}", exc_info=True)
                alignment_result = {
                    "status": "error",
                    "message": f"Failed to compute LO alignment: {str(e)}",
                }
        else:
            alignment_result = {
                "status": "UNAVAILABLE",
                "message": "No learning outcomes provided for alignment analysis.",
            }

        # ------------------------------------------------------------------
        # STEP 3: Semantic Similarity (Past Question Bank Comparison)
        # ------------------------------------------------------------------
        similarity_result: Optional[Dict[str, Any]] = None
        q_sim_match_map: Dict[Any, Dict[str, Any]] = {}

        if request.previous_questions and len(request.previous_questions) > 0:
            try:
                cur_q_items = [
                    CurrentQuestionItem(
                        id=q.id,
                        number=q.number,
                        text=q.text,
                        topics=_extract_topic_names(
                            enriched_q_map.get(q.number, {}).get("topics", [])
                            or q.topics
                            or []
                        ),
                    )
                    for q in request.questions
                ]
                prev_q_items = [
                    PreviousQuestionItem(
                        id=pq.id,
                        number=pq.number,
                        text=pq.text,
                        assessment_title=pq.assessment_title,
                        term=pq.term,
                        year=pq.year,
                    )
                    for pq in request.previous_questions
                ]
                similarity_result = self.similarity_analyzer.analyze(
                    current_questions=cur_q_items,
                    previous_questions=prev_q_items,
                    course_id=request.course_id,
                )

                # Map question number/id -> top similarity and duplicate flag
                for q_sim in similarity_result.get("question_similarities", []):
                    q_num = q_sim.get("question_number")
                    q_sim_match_map[q_num] = {
                        "similarity_score": q_sim.get("highest_similarity_score", 0.0),
                        "is_duplicate": q_sim.get("is_potential_duplicate", False),
                    }
            except Exception as e:
                logger.error(f"Error during semantic similarity in unified analysis: {e}", exc_info=True)
                similarity_result = {
                    "status": "error",
                    "message": f"Failed to compute semantic similarity: {str(e)}",
                }
        else:
            similarity_result = {
                "status": "UNAVAILABLE",
                "message": "No previous questions provided for similarity analysis.",
            }

        # ------------------------------------------------------------------
        # STEP 4: Assessment Quality Engine
        # ------------------------------------------------------------------
        quality_questions: List[AssessmentQuestionInput] = []
        for i, q in enumerate(request.questions):
            q_num = q.number or (i + 1)
            en_data = enriched_q_map.get(q_num, {})
            lo_data = q_lo_match_map.get(q_num, {})
            sim_data = q_sim_match_map.get(q_num, {})

            # Extract fields
            c_level = (
                en_data.get("cognitive_level", {}).get("level")
                or q.cognitive_level
                or "Understand"
            )
            diff_level = (
                en_data.get("difficulty", {}).get("level")
                or q.difficulty
                or "Medium"
            )
            q_type = (
                en_data.get("classification", {}).get("type")
                or q.question_type
                or "Descriptive"
            )
            q_topics = _extract_topic_names(
                en_data.get("topics", []) or q.topics or []
            )
            lo_code = lo_data.get("code") or q.learning_outcome_code
            sim_score = sim_data.get("similarity_score", q.similarity_score)
            is_dup = sim_data.get("is_duplicate", q.is_duplicate)

            quality_questions.append(
                AssessmentQuestionInput(
                    id=q.id,
                    number=int(q_num) if str(q_num).isdigit() else i + 1,
                    text=q.text,
                    marks=q.marks or 1.0,
                    question_type=q_type,
                    difficulty=diff_level,
                    cognitive_level=c_level,
                    topics=q_topics,
                    learning_outcome_code=lo_code,
                    similarity_score=sim_score,
                    is_duplicate=is_dup,
                )
            )

        quality_topics = [
            TopicInput(name=t) for t in (request.course_topics or [])
        ]
        quality_los = [
            LearningOutcomeInput(
                code=lo.code or f"LO{idx + 1}",
                description=lo.description,
                weight=lo.weight,
            )
            for idx, lo in enumerate(request.learning_outcomes or [])
        ]

        quality_weights = (
            QualityWeightsConfig(**request.weights)
            if request.weights
            else QualityWeightsConfig()
        )
        difficulty_targets = (
            DifficultyTargetsConfig(**request.difficulty_targets)
            if request.difficulty_targets
            else DifficultyTargetsConfig()
        )

        quality_request = QualityAnalysisRequest(
            assessment=AssessmentMetadataInput(
                id=request.assessment.id if request.assessment else None,
                title=request.assessment.title if request.assessment else None,
                total_marks=request.assessment.total_marks if request.assessment else None,
            )
            if request.assessment
            else None,
            questions=quality_questions,
            topics=quality_topics,
            learning_outcomes=quality_los,
            weights=quality_weights,
            difficulty_targets=difficulty_targets,
        )

        quality_response_model = self.quality_engine.analyze(quality_request)
        quality_result = quality_response_model.model_dump()

        # ------------------------------------------------------------------
        # STEP 5: AI Recommendation Engine
        # ------------------------------------------------------------------
        # Construct RecommendationRequest
        rec_assessment_meta = (
            AssessmentMeta(
                id=int(request.assessment.id) if request.assessment and str(request.assessment.id).isdigit() else None,
                title=request.assessment.title if request.assessment else None,
                total_marks=request.assessment.total_marks if request.assessment else None,
                course_code=request.assessment.course_code if request.assessment else None,
                course_title=request.assessment.course_title if request.assessment else None,
            )
            if request.assessment
            else None
        )

        rec_req = RecommendationRequest(
            assessment=rec_assessment_meta,
            topic_analysis=TopicAnalysisInput(**quality_result.get("topic_analysis", {}))
            if quality_result.get("topic_analysis")
            else None,
            learning_outcome_analysis=LoAnalysisInput(**quality_result.get("learning_outcome_analysis", {}))
            if quality_result.get("learning_outcome_analysis")
            else None,
            difficulty_analysis=DifficultyAnalysisInput(**quality_result.get("difficulty_analysis", {}))
            if quality_result.get("difficulty_analysis")
            else None,
            cognitive_analysis=CognitiveAnalysisInput(**quality_result.get("cognitive_analysis", {}))
            if quality_result.get("cognitive_analysis")
            else None,
            question_diversity_analysis=QuestionDiversityInput(**quality_result.get("question_diversity_analysis", {}))
            if quality_result.get("question_diversity_analysis")
            else None,
            marks_analysis=MarksAnalysisInput(**quality_result.get("marks_analysis", {}))
            if quality_result.get("marks_analysis")
            else None,
            similarity_analysis=SimilarityAnalysisInput(
                status=similarity_result.get("status") if similarity_result else None,
                overall_similarity_score=similarity_result.get("overall_similarity_score", 0.0) if similarity_result else 0.0,
                potential_duplicates_count=similarity_result.get("potential_duplicates_count", 0) if similarity_result else 0,
                highly_similar_count=similarity_result.get("highly_similar_count", 0) if similarity_result else 0,
                somewhat_similar_count=similarity_result.get("somewhat_similar_count", 0) if similarity_result else 0,
                total_questions=similarity_result.get("total_questions", len(request.questions)) if similarity_result else len(request.questions),
                matches=similarity_result.get("matches", []) if similarity_result else [],
                question_similarities=similarity_result.get("question_similarities", []) if similarity_result else [],
            )
            if similarity_result and similarity_result.get("status") != "UNAVAILABLE"
            else None,
            quality_analysis=QualityAnalysisInput(
                overall_quality_score=quality_result.get("overall_quality_score"),
                rating=quality_result.get("rating"),
                components=quality_result.get("components"),
            ),
            custom_rules=request.custom_rules,
        )

        rec_engine = RecommendationEngine(custom_thresholds=request.custom_rules)
        rec_response_model = rec_engine.generate(rec_req)
        rec_result = rec_response_model.model_dump()

        # ------------------------------------------------------------------
        # STEP 6: Consolidated Summary and Response
        # ------------------------------------------------------------------
        summary = {
            "total_questions": len(request.questions),
            "overall_quality_score": quality_result.get("overall_quality_score"),
            "quality_rating": quality_result.get("rating"),
            "lo_coverage_percentage": (
                alignment_result.get("coverage_percentage")
                if alignment_result and alignment_result.get("status") == "success"
                else (quality_result.get("learning_outcome_analysis", {}).get("coverage_percentage"))
            ),
            "topic_coverage_percentage": quality_result.get("topic_analysis", {}).get(
                "coverage_percentage"
            ),
            "difficulty_balance_score": quality_result.get("difficulty_analysis", {}).get(
                "balance_score"
            ),
            "cognitive_diversity_score": quality_result.get("cognitive_analysis", {}).get(
                "diversity_score"
            ),
            "total_recommendations": rec_result.get("total_recommendations", 0),
            "high_priority_recommendations": rec_result.get("high_priority_count", 0),
            "potential_duplicates_count": (
                similarity_result.get("potential_duplicates_count", 0)
                if similarity_result and similarity_result.get("status") == "success"
                else 0
            ),
        }

        return {
            "status": "success",
            "method": "unified_assessment_analysis_pipeline",
            "assessment_id": request.assessment.id if request.assessment else None,
            "course_id": request.course_id,
            "questions_analysis": batch_question_analysis,
            "alignment_analysis": alignment_result,
            "similarity_analysis": similarity_result,
            "quality_analysis": quality_result,
            "recommendations": rec_result,
            "summary": summary,
        }
