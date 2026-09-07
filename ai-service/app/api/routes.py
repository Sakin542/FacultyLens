import logging
from typing import Optional
from fastapi import APIRouter, Header, HTTPException, status, Depends
from app.config import get_settings, Settings
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.text_cleaner import TextCleaner
from app.services.analyzer import AcademicTextAnalyzer
from app.utils.text_utils import split_paragraphs, split_sentences
from app.schemas.analysis import (
    HealthResponse,
    PreprocessRequest,
    PreprocessResponse,
    AnalyzeRequest,
    AnalyzeResponse,
    EmbeddingRequest,
    EmbeddingResponse,
)

logger = logging.getLogger("facultylens.ai")

router = APIRouter()


def verify_api_key(
    x_ai_service_key: Optional[str] = Header(None, alias="X-AI-Service-Key"),
    settings: Settings = Depends(get_settings),
) -> None:
    """
    Verify internal service API key if configured in settings.
    """
    if settings.ai_service_api_key and settings.ai_service_api_key.strip():
        if not x_ai_service_key or x_ai_service_key.strip() != settings.ai_service_api_key.strip():
            logger.warning("Unauthorized access attempt: Invalid X-AI-Service-Key provided.")
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid or missing X-AI-Service-Key header.",
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

