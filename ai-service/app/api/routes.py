import logging
from typing import Optional
from fastapi import APIRouter, Header, HTTPException, status, Depends
from app.config import get_settings, Settings
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.text_cleaner import TextCleaner
from app.services.analyzer import AcademicTextAnalyzer
from app.services.question_analyzer import QuestionAnalyzer
from app.services.alignment_analyzer import AlignmentAnalyzer
from app.services.semantic_similarity_analyzer import SemanticSimilarityAnalyzer
from app.services.assessment_quality_engine import AssessmentQualityEngine
from app.services.recommendation_engine import RecommendationEngine
from app.services.assessment_analysis_service import AssessmentAnalysisService
from app.services.rubric_generator import RubricGenerator
from app.services.rubric_validator import RubricValidationError
from app.services.grading_engine import GradingEngine
from app.services.grading_validator import GradingValidationError
from app.services.text_generation_service import TextGenerationService, get_text_generation_service
from app.utils.text_utils import split_paragraphs, split_sentences
from app.schemas.analysis import (
    HealthResponse,
    PreprocessRequest,
    PreprocessResponse,
    AnalyzeRequest,
    AnalyzeResponse,
    EmbeddingRequest,
    EmbeddingResponse,
    AnalyzeSingleQuestionRequest,
    AnalyzeSingleQuestionResponse,
    AnalyzeBatchQuestionsRequest,
    AnalyzeBatchQuestionsResponse,
)
from app.schemas.alignment import (
    AnalyzeAlignmentRequest,
    AnalyzeAlignmentResponse,
)
from app.schemas.similarity import (
    AnalyzeSimilarityRequest,
    AnalyzeSimilarityResponse,
)
from app.schemas.quality import (
    QualityAnalysisRequest,
    QualityAnalysisResponse,
)
from app.schemas.recommendation import (
    RecommendationRequest,
    RecommendationResponse,
)
from app.schemas.assessment_analysis import (
    UnifiedAssessmentAnalysisRequest,
    UnifiedAssessmentAnalysisResponse,
)
from app.schemas.rubric import (
    GenerateRubricRequest,
    GenerateRubricResponse,
)
from app.schemas.grading import (
    GradeAnswerRequest,
    GradeAnswerResponse,
)

logger = logging.getLogger("facultylens.ai")

router = APIRouter()


def verify_api_key(
    x_ai_service_key: Optional[str] = Header(None, alias="X-AI-Service-Key"),
    authorization: Optional[str] = Header(None, alias="Authorization"),
    settings: Settings = Depends(get_settings),
) -> None:
    """
    Verify internal service API key if configured in settings.
    Accepts either X-AI-Service-Key or Authorization: Bearer <key>.
    """
    expected_key = (settings.ai_service_api_key or "").strip()
    if expected_key:
        provided_key = None
        if x_ai_service_key and x_ai_service_key.strip():
            provided_key = x_ai_service_key.strip()
        elif authorization and authorization.strip():
            parts = authorization.strip().split()
            if len(parts) == 2 and parts[0].lower() == "bearer":
                provided_key = parts[1].strip()
            else:
                provided_key = authorization.strip()

        if not provided_key or provided_key != expected_key:
            logger.warning("Unauthorized access attempt: Invalid or missing internal service key.")
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid or missing service authentication credentials.",
            )


@router.get("/health", response_model=HealthResponse)
def health_check(
    hf_service: HuggingFaceService = Depends(get_hf_service),
    settings: Settings = Depends(get_settings),
) -> HealthResponse:
    """
    Health check endpoint returning service status and model information.
    """
    return HealthResponse(
        status="ok",
        service=settings.app_name,
        model=hf_service.model_name,
        model_loaded=hf_service.is_loaded,
    )


@router.post(
    "/api/v1/preprocess",
    response_model=PreprocessResponse,
    dependencies=[Depends(verify_api_key)],
)
def preprocess_text(payload: PreprocessRequest) -> PreprocessResponse:
    """
    Clean academic text and extract paragraph and sentence segments.
    """
    try:
        cleaned = TextCleaner.clean(payload.text)
        paragraphs = split_paragraphs(cleaned)
        sentences = split_sentences(cleaned)

        return PreprocessResponse(
            status="success",
            cleaned_text=cleaned,
            paragraphs=paragraphs,
            sentences=sentences,
        )
    except Exception as e:
        logger.error(f"Error preprocessing text: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to preprocess academic text.",
        )


@router.post(
    "/api/v1/analyze",
    response_model=AnalyzeResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_academic_text(
    payload: AnalyzeRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeResponse:
    """
    Run complete academic text analysis including question detection,
    statistical metrics, and Hugging Face embedding generation.
    """
    try:
        analyzer = AcademicTextAnalyzer(hf_service=hf_service)
        result = analyzer.analyze(text=payload.text, document_type=payload.document_type)

        return AnalyzeResponse(
            status="success",
            document_type=result["document_type"],
            analysis=result["analysis"],
        )
    except Exception as e:
        logger.error(f"Error during academic text analysis: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to analyze academic text.",
        )


@router.post(
    "/api/v1/embedding",
    response_model=EmbeddingResponse,
    dependencies=[Depends(verify_api_key)],
)
def generate_embedding(
    payload: EmbeddingRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> EmbeddingResponse:
    """
    Generate embedding for testing or specific text segments using the loaded model.
    """
    try:
        vector = hf_service.generate_embedding(payload.text)
        return EmbeddingResponse(
            status="success",
            embedding_dimension=hf_service.embedding_dimension,
            model=hf_service.model_name,
            vector=vector if payload.return_vector else None,
        )
    except Exception as e:
        logger.error(f"Error generating embedding: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to generate embedding: {str(e)}",
        )


@router.post(
    "/api/v1/analyze-question",
    response_model=AnalyzeSingleQuestionResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_single_question(
    payload: AnalyzeSingleQuestionRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeSingleQuestionResponse:
    """
    Analyze a single academic question across classification, topics, difficulty, and Bloom's cognitive level.
    """
    try:
        analyzer = QuestionAnalyzer(hf_service=hf_service)
        result = analyzer.analyze_single(
            question_text=payload.question,
            course_topics=payload.course_topics,
        )

        return AnalyzeSingleQuestionResponse(
            status="success",
            question=result["question"],
            classification=result["classification"],
            topics=result["topics"],
            difficulty=result["difficulty"],
            cognitive_level=result["cognitive_level"],
        )
    except Exception as e:
        logger.error(f"Error analyzing single question: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to analyze question.",
        )


@router.post(
    "/api/v1/analyze-questions",
    response_model=AnalyzeBatchQuestionsResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_batch_questions(
    payload: AnalyzeBatchQuestionsRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeBatchQuestionsResponse:
    """
    Analyze multiple academic questions in batch mode with summary distributions.
    """
    try:
        analyzer = QuestionAnalyzer(hf_service=hf_service)
        q_dicts = [{"number": q.number, "text": q.text} for q in payload.questions]
        result = analyzer.analyze_batch(
            questions=q_dicts,
            course_topics=payload.course_topics,
        )

        return AnalyzeBatchQuestionsResponse(
            status="success",
            total_questions=result["total_questions"],
            questions=result["questions"],
            summary=result["summary"],
        )
    except Exception as e:
        logger.error(f"Error analyzing batch questions: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to analyze question batch.",
        )


@router.post(
    "/api/v1/analyze-alignment",
    response_model=AnalyzeAlignmentResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_learning_outcome_alignment(
    payload: AnalyzeAlignmentRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeAlignmentResponse:
    """
    Analyze Learning Outcome alignment for a set of examination questions against course LOs.
    Computes dense semantic embeddings, pairwise cosine similarity matrix, LO coverage,
    overall alignment score, and actionable findings.
    """
    try:
        analyzer = AlignmentAnalyzer(hf_service=hf_service)
        result = analyzer.analyze(
            questions=payload.questions,
            learning_outcomes=payload.learning_outcomes,
            thresholds=payload.thresholds,
            course_id=payload.course_id,
        )

        return AnalyzeAlignmentResponse(**result)
    except Exception as e:
        logger.error(f"Error analyzing LO alignment: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to analyze learning outcome alignment.",
        )


@router.post(
    "/api/v1/analyze-similarity",
    response_model=AnalyzeSimilarityResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_semantic_similarity(
    payload: AnalyzeSimilarityRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeSimilarityResponse:
    """
    Analyze Semantic Similarity between current exam questions and historical previous questions.
    Computes dense embeddings, vectorized cosine similarities, top-K match rankings,
    duplicate/similar classifications, and explainable findings.
    """
    try:
        analyzer = SemanticSimilarityAnalyzer(hf_service=hf_service)
        result = analyzer.analyze(
            current_questions=payload.current_questions,
            previous_questions=payload.previous_questions,
            thresholds=payload.thresholds,
            top_k=payload.top_k,
            course_id=payload.course_id,
        )

        return AnalyzeSimilarityResponse(**result)
    except Exception as e:
        logger.error(f"Error analyzing semantic similarity: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to analyze question semantic similarity.",
        )


@router.post(
    "/api/v1/analyze-assessment-quality",
    response_model=QualityAnalysisResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_assessment_quality(
    payload: QualityAnalysisRequest,
) -> QualityAnalysisResponse:
    """
    Evaluate examination quality across 6 core pedagogical dimensions:
    1. Topic Coverage
    2. Learning Outcome Coverage
    3. Difficulty Balance
    4. Cognitive Diversity (Bloom's Taxonomy)
    5. Question Diversity
    6. Marks Distribution & Assessment Integrity

    Produces transparent sub-scores, explainable findings, and weighted overall assessment quality rating.
    """
    try:
        engine = AssessmentQualityEngine()
        return engine.analyze(payload)
    except Exception as e:
        logger.error(f"Error evaluating assessment quality: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to evaluate assessment quality: {str(e)}",
        )


@router.post(
    "/api/v1/generate-recommendations",
    response_model=RecommendationResponse,
    dependencies=[Depends(verify_api_key)],
)
def generate_recommendations(
    payload: RecommendationRequest,
) -> RecommendationResponse:
    """
    Generate prioritized, evidence-based pedagogical recommendations based on
    detected problems across topic coverage, LO alignment, difficulty balance,
    Bloom cognitive diversity, question formats, marks integrity, and past similarity.
    """
    try:
        engine = RecommendationEngine(custom_thresholds=payload.custom_rules)
        return engine.generate(payload)
    except Exception as e:
        logger.error(f"Error generating recommendations: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to generate recommendations: {str(e)}",
        )


@router.post(
    "/api/v1/analyze-assessment",
    response_model=UnifiedAssessmentAnalysisResponse,
    dependencies=[Depends(verify_api_key)],
)
def analyze_assessment_unified(
    payload: UnifiedAssessmentAnalysisRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> UnifiedAssessmentAnalysisResponse:
    """
    STEP 15: Unified AI Assessment Analysis endpoint.
    Orchestrates Question Analysis, Learning Outcome Alignment, Semantic Similarity,
    Assessment Quality Engine, and AI Recommendation Engine in a single consolidated workflow.
    """
    try:
        service = AssessmentAnalysisService(hf_service=hf_service)
        result = service.analyze_assessment(payload)
        return UnifiedAssessmentAnalysisResponse(**result)
    except Exception as e:
        logger.error(f"Error in unified assessment analysis: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Failed to perform unified assessment analysis: {str(e)}",
        )


@router.post(
    "/api/v1/generate-rubric",
    response_model=GenerateRubricResponse,
    dependencies=[Depends(verify_api_key)],
)
def generate_rubric(
    payload: GenerateRubricRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
    text_generation_service: TextGenerationService = Depends(get_text_generation_service),
) -> GenerateRubricResponse:
    """
    STEP 25: AI Rubric Generator.
    Produces a structured DRAFT grading rubric for a single question. The draft is
    validated so criterion marks sum exactly to the question total. Faculty review
    and approval happen in Laravel; this endpoint never finalizes a rubric.
    """
    try:
        generator = RubricGenerator(
            hf_service=hf_service,
            text_generation_service=text_generation_service,
        )
        result = generator.generate(payload)
        return GenerateRubricResponse(**result)
    except RubricValidationError as e:
        logger.warning(f"Generated rubric failed validation: {e}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="The AI service could not produce a valid rubric for this question.",
        )
    except Exception as e:
        logger.error(f"Error generating rubric: {e}", exc_info=True)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to generate rubric draft.",
        )


@router.post(
    "/api/v1/grade-answer",
    response_model=GradeAnswerResponse,
    dependencies=[Depends(verify_api_key)],
)
def grade_answer(
    payload: GradeAnswerRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
    text_generation_service: TextGenerationService = Depends(get_text_generation_service),
) -> GradeAnswerResponse:
    """
    STEP 27: AI Grading Assistance.
    Aligns a student answer with an approved rubric and returns *suggested* marks,
    criterion-level evidence, missing elements and draft feedback. Faculty review
    and the final grade happen in Laravel; this endpoint never finalizes a grade.
    Student answer text is not logged.
    """
    try:
        engine = GradingEngine(
            hf_service=hf_service,
            text_generation_service=text_generation_service,
        )
        result = engine.grade(payload)
        return GradeAnswerResponse(**result)
    except GradingValidationError as e:
        logger.warning(f"Grading suggestion failed validation: {e}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="The AI service could not produce a valid grading suggestion for this answer.",
        )
    except Exception as e:
        logger.error(f"Error grading answer: {type(e).__name__}", exc_info=False)
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Failed to generate grading assistance.",
        )


# API aliases for consistent naming convention across STEP 15 specification
@router.post(
    "/api/v1/question-analysis",
    response_model=AnalyzeBatchQuestionsResponse,
    dependencies=[Depends(verify_api_key)],
)
def question_analysis_alias(
    payload: AnalyzeBatchQuestionsRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeBatchQuestionsResponse:
    """Alias for /api/v1/analyze-questions."""
    return analyze_batch_questions(payload, hf_service)


@router.post(
    "/api/v1/similarity-analysis",
    response_model=AnalyzeSimilarityResponse,
    dependencies=[Depends(verify_api_key)],
)
def similarity_analysis_alias(
    payload: AnalyzeSimilarityRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeSimilarityResponse:
    """Alias for /api/v1/analyze-similarity."""
    return analyze_semantic_similarity(payload, hf_service)


@router.post(
    "/api/v1/alignment-analysis",
    response_model=AnalyzeAlignmentResponse,
    dependencies=[Depends(verify_api_key)],
)
def alignment_analysis_alias(
    payload: AnalyzeAlignmentRequest,
    hf_service: HuggingFaceService = Depends(get_hf_service),
) -> AnalyzeAlignmentResponse:
    """Alias for /api/v1/analyze-alignment."""
    return analyze_learning_outcome_alignment(payload, hf_service)







