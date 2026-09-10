"""STEP 35: model/prompt inventory exposed for the Laravel model registry (no secrets)."""

from typing import Any, Dict, List

from app.config import get_settings
from app.services import academic_chat, grading_engine, rubric_alignment_analyzer, rubric_generator
from app.services import prompt_builder, question_generation_prompt
from app.services import question_generator as qgen


def model_inventory(hf_service: Any, generation_service: Any) -> Dict[str, Any]:
    settings = get_settings()
    embedding = {
        "model_name": getattr(hf_service, "model_name", settings.hf_model_name),
        "provider": "Hugging Face",
        "model_type": "embedding",
        "version": "configured",
        "configuration": {"embedding_dimension": _safe(lambda: hf_service.embedding_dimension), "note": "Embedding/similarity model only — not a text generator."},
    }
    gen_name = getattr(generation_service, "model_name", None) or settings.hf_generation_model
    generation = {
        "model_name": gen_name or "not-configured",
        "provider": "Hugging Face" if gen_name else "none",
        "model_type": "generation",
        "version": "configured",
        "configuration": {"configured": bool(gen_name), "max_new_tokens": settings.hf_generation_max_new_tokens},
    }
    engines: List[Dict[str, Any]] = [
        {"model_name": "facultylens-question-analyzer", "provider": "FacultyLens", "model_type": "rule_engine", "version": "1.0.0",
         "tasks": ["QUESTION_CLASSIFICATION", "DIFFICULTY_CLASSIFICATION", "BLOOM_CLASSIFICATION"], "configuration": {"method": "heuristic patterns + MiniLM topics"}},
        {"model_name": rubric_generator.TEMPLATE_ENGINE_NAME, "provider": "FacultyLens", "model_type": "template_engine", "version": rubric_generator.ENGINE_VERSION, "tasks": ["RUBRIC_GENERATION"]},
        {"model_name": grading_engine.ENGINE_NAME, "provider": "FacultyLens", "model_type": "rule_engine", "version": grading_engine.ENGINE_VERSION, "tasks": ["GRADING_ASSISTANCE"]},
        {"model_name": rubric_alignment_analyzer.ENGINE_NAME, "provider": "FacultyLens", "model_type": "rule_engine", "version": "1.0.0", "tasks": ["ANSWER_RUBRIC_ALIGNMENT"]},
        {"model_name": academic_chat.ENGINE_NAME, "provider": "FacultyLens", "model_type": "extractive_engine", "version": academic_chat.ENGINE_VERSION, "tasks": ["DOCUMENT_CHAT"]},
        {"model_name": qgen.TEMPLATE_ENGINE_NAME, "provider": "FacultyLens", "model_type": "template_engine", "version": qgen.ENGINE_VERSION, "tasks": ["QUESTION_GENERATION"]},
    ]
    prompts = [
        {"feature": "document_chat", "version": prompt_builder.PROMPT_VERSION, "prompt_hash": _hash(prompt_builder.SYSTEM_INSTRUCTIONS), "description": "Grounded chat system instructions"},
        {"feature": "question_generation", "version": question_generation_prompt.PROMPT_VERSION, "prompt_hash": _hash(question_generation_prompt.SYSTEM_INSTRUCTIONS), "description": "Constrained question drafting instructions"},
        {"feature": "rubric_generation", "version": rubric_generator.ENGINE_VERSION, "prompt_hash": None, "description": "Rubric template engine (optional seq2seq criterion proposals)"},
        {"feature": "grading_assistance", "version": grading_engine.ENGINE_VERSION, "prompt_hash": None, "description": "Rubric-driven grading engine"},
    ]
    return {
        "status": "success",
        "embedding_model": embedding,
        "generation_model": generation,
        "engines": engines,
        "prompt_versions": prompts,
        "thresholds": {
            "similarity": {"duplicate": settings.similarity_duplicate_threshold, "high": settings.similarity_high_threshold, "moderate": settings.similarity_moderate_threshold},
            "chat_min_relevance": settings.chat_min_relevance_score,
            "question_generation_alignment": {"strong": settings.question_generation_alignment_strong, "weak": settings.question_generation_alignment_weak},
        },
    }


def _safe(fn):
    try:
        return fn()
    except Exception:
        return None


def _hash(text: str) -> str:
    import hashlib

    return hashlib.sha256(text.encode("utf-8")).hexdigest()
