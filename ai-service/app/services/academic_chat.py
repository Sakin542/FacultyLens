"""STEP 32: Academic document chat orchestration (RAG answer step).

    authorized, pre-scored chunks (from Laravel)
        -> relevance filter / top-K / context budget          (retrieval.py)
        -> grounded prompt with untrusted-context delimiters   (prompt_builder.py)
        -> configurable generation model, or extractive fallback (generation_service.py)
        -> answer + sources limited to the chunks actually used

grounded=True only when relevant context existed and was used. Without evidence the assistant
says so instead of answering from model knowledge. The source list is derived from the context
blocks actually placed in the prompt (and, for generative answers, the [S#] citations it emitted).
"""

import logging
import re
from typing import Any, Dict, List, Optional

from app.config import get_settings
from app.schemas.chat import AcademicChatRequest
from app.services.prompt_builder import INSUFFICIENT_EVIDENCE_TEXT, PROMPT_VERSION, PromptBuilder
from app.services.retrieval import best_sentences, select_context

logger = logging.getLogger("facultylens.ai")

ENGINE_NAME = "facultylens-extractive-answer-engine"
ENGINE_VERSION = "1.0.0"
_CITATION = re.compile(r"\[S(\d+)\]")
_LEAK_PATTERNS = re.compile(r"(<<<SYSTEM INSTRUCTIONS>>>|You are FacultyLens Academic Document Assistant|HF_TOKEN|api[_ ]?key\s*[:=])", re.IGNORECASE)


class AcademicChatService:
    def __init__(self, generation_service: Any = None, hf_service: Any = None) -> None:
        self.generation = generation_service
        self.hf_service = hf_service
        self.settings = get_settings()
        self.prompts = PromptBuilder()

    def answer(self, request: AcademicChatRequest) -> Dict[str, Any]:
        chunks = [c.model_dump() for c in request.chunks]
        context = select_context(chunks)
        conversation = [t.model_dump() for t in request.conversation][-self.settings.chat_max_history_messages:]
        embedding_model = getattr(self.hf_service, "model_name", None) or self.settings.hf_model_name

        base = {
            "status": "success",
            "embedding_model": embedding_model,
            "prompt_version": PROMPT_VERSION,
            "retrieved_count": len(chunks),
            "model_version": ENGINE_VERSION,
        }

        if not context:
            return base | {
                "answer": INSUFFICIENT_EVIDENCE_TEXT,
                "sources": [],
                "grounded": False,
                "generation_method": "insufficient_evidence",
                "model": ENGINE_NAME,
                "used_count": 0,
            }

        prompt = self.prompts.build(request.question, context, conversation, request.context.model_dump())

        generated = None
        if self.generation and getattr(self.generation, "is_configured", False):
            try:
                generated = self.generation.generate(prompt)
            except Exception as e:  # defensive: generation must never break the request
                logger.warning(f"Generation failed: {type(e).__name__}")
                generated = None

        if generated and self._is_usable(generated):
            answer = self._strip_leaks(generated)
            cited = {int(m) for m in _CITATION.findall(answer)}
            used = [c for i, c in enumerate(context, start=1) if i in cited] if cited else context
            if INSUFFICIENT_EVIDENCE_TEXT.lower() in answer.lower():
                return base | {"answer": INSUFFICIENT_EVIDENCE_TEXT, "sources": [], "grounded": False,
                               "generation_method": "generative", "model": self.generation.model_name, "used_count": 0}
            return base | {
                "answer": answer,
                "sources": [self._source(c) for c in used],
                "grounded": True,
                "generation_method": "generative",
                "model": self.generation.model_name,
                "used_count": len(used),
            }

        return self._extractive(request.question, context) | base

    # ---------------------------------------------------------------- helpers

    def _extractive(self, question: str, context: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Evidence-only answer: quote the most relevant sentences with [S#] citations. No generation."""
        sentences = best_sentences(question, context, limit=4)
        # Require at least one sentence that lexically overlaps the question, otherwise say so.
        if not sentences or all(s["overlap"] == 0 for s in sentences):
            return {"answer": INSUFFICIENT_EVIDENCE_TEXT, "sources": [], "grounded": False,
                    "generation_method": "insufficient_evidence", "model": ENGINE_NAME, "used_count": 0}
        index = {c["chunk_id"]: i for i, c in enumerate(context, start=1)}
        lines = []
        used_ids: List[int] = []
        for s in sentences:
            if s["overlap"] == 0:
                continue
            n = index.get(s["chunk_id"])
            lines.append(f"• {s['sentence']} [S{n}]")
            if s["chunk_id"] not in used_ids:
                used_ids.append(s["chunk_id"])
        used = [c for c in context if c["chunk_id"] in used_ids]
        answer = (
            "Based on the uploaded documents, the most relevant passages are:\n" + "\n".join(lines) +
            "\n\n(No generation model is configured, so this answer quotes document evidence rather than paraphrasing it.)"
        )
        return {
            "answer": answer,
            "sources": [self._source(c) for c in used],
            "grounded": True,
            "generation_method": "extractive",
            "model": ENGINE_NAME,
            "used_count": len(used),
        }

    @staticmethod
    def _source(c: Dict[str, Any]) -> Dict[str, Any]:
        return {
            "chunk_id": c["chunk_id"],
            "document_id": c["document_id"],
            "document_name": c["document_name"],
            "page_number": c.get("page_number"),
            "section_title": c.get("section_title"),
            "similarity_score": round(float(c.get("similarity_score", 0.0)), 4),
        }

    @staticmethod
    def _is_usable(text: str) -> bool:
        t = text.strip()
        return len(t) >= 8 and bool(re.search(r"[A-Za-z]", t)) and not _LEAK_PATTERNS.search(t)

    @staticmethod
    def _strip_leaks(text: str) -> str:
        return _LEAK_PATTERNS.sub("[redacted]", text).strip()
