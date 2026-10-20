"""AI toolkit endpoints: OCR, transcription, translation, speech, study packs, question checks, simplify, Bloom rewrite, feedback drafts, paper review,
answer-similarity embeddings."""

import logging
from typing import List, Optional

from fastapi import APIRouter, Depends, File, Form, HTTPException, Response, UploadFile, status
from fastapi.concurrency import run_in_threadpool
from pydantic import BaseModel, Field

from app.api.routes import verify_api_key
from app.services import model_memory, provider_context
from app.services.answer_embedder import get_answer_embedder
from app.services.feedback_drafter import FeedbackDrafter
from app.services.paper_review import PaperReviewer
from app.services.generation_service import GenerationService, get_generation_service
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.services.toolkit import ToolkitError, get_ocr, get_speaker, get_transcriber, get_translator
from app.services.writing_tools import BloomRewriter, QuestionQualityChecker, Simplifier, StudyPackBuilder, WritingToolError

logger = logging.getLogger("facultylens.ai")
router = APIRouter(prefix="/api/v1/toolkit", tags=["toolkit"], dependencies=[Depends(verify_api_key)])

MAX_UPLOAD_BYTES = 200 * 1024 * 1024


class TranslateRequest(BaseModel):
    text: str = Field(..., max_length=20000)
    source: str = Field(..., pattern="^(en|bn)$")
    target: str = Field(..., pattern="^(en|bn)$")


class AnswerEmbeddingRequest(BaseModel):
    texts: List[str] = Field(..., min_length=1, max_length=500)


class SpeakRequest(BaseModel):
    text: str = Field(..., max_length=5000)
    language: str = Field(..., pattern="^(en|bn)$")


class StudyPackRequest(BaseModel):
    text: str = Field(..., max_length=80000)
    title: Optional[str] = Field(None, max_length=200)
    num_questions: int = Field(5, ge=1, le=10)


class QuestionQualityRequest(BaseModel):
    questions: List[str] = Field(..., min_length=1, max_length=30)
    suggest_rewrites: bool = True


class SimplifyRequest(BaseModel):
    text: str = Field(..., max_length=6000)
    level: str = Field("undergraduate", pattern="^(school|undergraduate|esl)$")


class BloomRewriteRequest(BaseModel):
    question: str = Field(..., max_length=2000)
    target_level: str = Field(..., pattern="^(REMEMBER|UNDERSTAND|APPLY|ANALYZE|EVALUATE|CREATE)$")


class FeedbackCriterion(BaseModel):
    criterion: str = Field(..., min_length=1, max_length=255)
    description: Optional[str] = Field(None, max_length=2000)
    indicators: List[str] = Field(default_factory=list, max_length=12)


class FeedbackDraftRequest(BaseModel):
    question: str = Field(..., max_length=10000)
    answer: str = Field(..., max_length=20000)
    criteria: List[FeedbackCriterion] = Field(..., min_length=1, max_length=12)
    expected_answer: Optional[str] = Field(None, max_length=10000)
    tone: str = Field("encouraging", pattern="^(encouraging|neutral|concise)$")


class PaperQuestion(BaseModel):
    number: Optional[str] = Field(None, max_length=20)
    text: str = Field(..., max_length=5000)
    marks: Optional[float] = Field(None, ge=0, le=1000)
    bloom_level: Optional[str] = Field(None, max_length=20)


class PaperReviewRequest(BaseModel):
    questions: List[PaperQuestion] = Field(..., min_length=1, max_length=60)
    topics: List[str] = Field(default_factory=list, max_length=30)
    targets: Optional[dict] = None


def _fail(e: Exception) -> HTTPException:
    if isinstance(e, (ToolkitError, WritingToolError)):
        return HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(e))
    logger.exception("Toolkit request failed")
    return HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"The AI tool failed ({type(e).__name__}).")


async def _read(upload: UploadFile) -> bytes:
    data = await upload.read(MAX_UPLOAD_BYTES + 1)
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, detail="The file is too large.")
    if not data:
        raise HTTPException(status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail="The file is empty.")
    return data


@router.get("/status")
def toolkit_status(generation: GenerationService = Depends(get_generation_service)):
    """Configured models, whether they are loaded and whether their system dependencies are installed."""
    tools = {
        "ocr": get_ocr(), "transcription": get_transcriber(), "translation": get_translator(),
    }
    speaker = get_speaker()
    embedder = get_answer_embedder()

    def shown(tool):
        # OCR and transcription list their hosted models first when the administrator selected the hosted provider
        return tool.display_model() if hasattr(tool, "display_model") else tool.model_name

    return {
        "status": "success",
        "generation": generation.status(),
        "memory": model_memory.status(),
        "tools": {
            **{k: {"model": shown(t), "loaded": t.loaded, "dependencies": t.dependencies(), "ready": all(t.dependencies().values())} for k, t in tools.items()},
            "speech": {"model": f"{speaker.model_name('en')} (English), {speaker.model_name('bn')} (Bangla)", "loaded": bool(speaker.loaded_languages),
                       "dependencies": {"kokoro": speaker.kokoro_enabled}, "ready": True},
            "similarity": {"model": embedder.model_name, "loaded": embedder.loaded, "dependencies": {}, "ready": True},
            # Generation-based tools reuse the shared generator; they need no model of their own
            "writing": {"model": generation.model_name or "template / rule engines", "loaded": generation.is_loaded, "dependencies": {}, "ready": True},
        },
    }


@router.post("/ocr")
async def ocr(file: UploadFile = File(...)):
    data = await _read(file)
    try:
        result = await run_in_threadpool(get_ocr().run, data, file.filename or "upload")
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/transcribe")
async def transcribe(file: UploadFile = File(...), language: Optional[str] = Form(None)):
    data = await _read(file)
    try:
        result = await run_in_threadpool(get_transcriber().run, data, language if language in ("en", "bn") else None)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/translate")
def translate(payload: TranslateRequest, generation: GenerationService = Depends(get_generation_service)):
    try:
        result = get_translator().run(payload.text, payload.source, payload.target, generation_service=generation)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", "requested_provider": provider_context.requested_provider(), **result}


@router.post("/answer-embeddings")
def answer_embeddings(payload: AnswerEmbeddingRequest):
    """Embeddings for the answer similarity / plagiarism check (bge-m3, MiniLM if it cannot load). Text is never logged."""
    try:
        vectors, model = get_answer_embedder().embed(payload.texts)
    except Exception as e:
        logger.error(f"Answer embeddings failed: {type(e).__name__}")
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="Failed to embed the answers.")
    return {"status": "success", "model": model, "embedding_dimension": len(vectors[0]) if vectors else 0, "vectors": vectors}


@router.post("/speak")
def speak(payload: SpeakRequest):
    """Text to speech; returns a WAV file."""
    try:
        result = get_speaker().run(payload.text, payload.language)
    except Exception as e:
        raise _fail(e)
    return Response(content=result["audio"], media_type="audio/wav",
                    headers={"X-Audio-Seconds": str(result["seconds"]), "X-Model": result["model"], "Cache-Control": "no-store"})


@router.post("/study-pack")
def study_pack(payload: StudyPackRequest, generation: GenerationService = Depends(get_generation_service), hf: HuggingFaceService = Depends(get_hf_service)):
    try:
        result = StudyPackBuilder(generation, hf).build(payload.text, payload.title, payload.num_questions)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/question-quality")
def question_quality(payload: QuestionQualityRequest, generation: GenerationService = Depends(get_generation_service)):
    try:
        result = QuestionQualityChecker(generation).check(payload.questions, payload.suggest_rewrites)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/simplify")
def simplify(payload: SimplifyRequest, generation: GenerationService = Depends(get_generation_service)):
    try:
        result = Simplifier(generation).simplify(payload.text, payload.level)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/bloom-rewrite")
def bloom_rewrite(payload: BloomRewriteRequest, generation: GenerationService = Depends(get_generation_service)):
    try:
        result = BloomRewriter(generation).rewrite(payload.question, payload.target_level)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/feedback-draft")
def feedback_draft(payload: FeedbackDraftRequest, generation: GenerationService = Depends(get_generation_service), hf: HuggingFaceService = Depends(get_hf_service)):
    """Rubric-based feedback for one answer, addressed to the student. Never returns marks."""
    try:
        result = FeedbackDrafter(generation, hf).draft(
            payload.question, [c.model_dump() for c in payload.criteria], payload.answer, payload.tone, payload.expected_answer,
        )
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}


@router.post("/paper-review")
def paper_review(payload: PaperReviewRequest, hf: HuggingFaceService = Depends(get_hf_service)):
    """Bloom balance, topic coverage, duplicate questions and wording problems for a whole paper."""
    targets = None
    if payload.targets:
        targets = {}
        for band in ("lower", "middle", "higher"):
            value = payload.targets.get(band)
            if isinstance(value, (list, tuple)) and len(value) == 2 and all(isinstance(v, (int, float)) for v in value) and 0 <= value[0] <= value[1] <= 100:
                targets[band] = (float(value[0]), float(value[1]))
    try:
        result = PaperReviewer(hf).review([q.model_dump() for q in payload.questions], [t[:300] for t in payload.topics], targets)
    except Exception as e:
        raise _fail(e)
    return {"status": "success", **result}
