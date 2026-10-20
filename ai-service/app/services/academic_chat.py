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
from typing import Any, Dict, List

from app.config import get_settings
from app.schemas.chat import AcademicChatRequest
from app.services import safety
from app.services.prompt_builder import INSUFFICIENT_EVIDENCE_TEXT, PROMPT_VERSION, PromptBuilder
from app.services.claim_check import abbreviations, expand, get_claim_verifier, judge_with_llm
from app.services.feedback_drafter import _stems
from app.services.retrieval import best_sentences, is_overview_question, overview_subject_supported, select_context

logger = logging.getLogger("facultylens.ai")

ENGINE_NAME = "facultylens-extractive-answer-engine"
ENGINE_VERSION = "1.1.0"
_LEAK_PATTERNS = re.compile(r"(<<<SYSTEM INSTRUCTIONS>>>|You are FacultyLens Academic Document Assistant|HF_TOKEN|api[_ ]?key\s*[:=])", re.IGNORECASE)

EVIDENCE_SUFFICIENT = "SUFFICIENT"
EVIDENCE_INSUFFICIENT = "INSUFFICIENT"
EVIDENCE_CONFLICTING = "CONFLICTING"


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

        # STEP 46: annotate (never block) instruction-like text; it stays untrusted data in the prompt.
        injected_chunks = safety.scan_chunks_for_injection(chunks)
        question_injection = safety.detect_prompt_injection(request.question)["detected"]
        if injected_chunks or question_injection:
            safety.log_safety_event(
                safety.EVENT_PROMPT_INJECTION_DETECTED,
                surface="academic_chat",
                chunk_count=len(injected_chunks),
                in_question=question_injection,
            )
        safety_info = {
            "injection_detected": bool(injected_chunks or question_injection),
            "injection_chunk_ids": injected_chunks,
            "conflicting_evidence": [],
            "citation_validation": "not_applicable",
        }

        base = {
            "status": "success",
            "embedding_model": embedding_model,
            "prompt_version": PROMPT_VERSION,
            "retrieved_count": len(chunks),
            "model_version": ENGINE_VERSION,
            "safety": safety_info,
        }

        if not context:
            return base | self._insufficient(ENGINE_NAME, "insufficient_evidence")

        # STEP 46: documents that disagree on a number the question asks about → surface both, decide nothing.
        conflicts = safety.detect_conflicting_evidence(context, request.question)
        if conflicts:
            safety.log_safety_event(safety.EVENT_CONFLICTING_EVIDENCE, surface="academic_chat", subjects=len(conflicts))
            safety_info["conflicting_evidence"] = conflicts
            return base | self._conflicting(context, conflicts)

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
            if INSUFFICIENT_EVIDENCE_TEXT.lower() in answer.lower():
                return base | self._insufficient(self.generation.model_name, "generative")

            rejection = self._reject_reason(answer, len(context))
            if rejection:
                safety.log_safety_event(rejection, surface="academic_chat", generation_method="generative")
                safety_info["citation_validation"] = "failed" if rejection == safety.EVENT_CITATION_VALIDATION_FAILED else "not_applicable"
                return self._extractive(request.question, context) | base

            # Every claim must be backed by one passage of the documents. Small models fill gaps with plausible
            # invented detail ("linear probing keeps a list of free slots"); such an answer is replaced by the
            # evidence-only answer, which quotes the documents instead of paraphrasing them.
            # Overview questions ("what does this document cover?") are answered by description and paraphrase,
            # which a word-level check cannot judge fairly, so the check applies to specific questions.
            unsupported = [] if not self.has_specific_subject(request.question) else self.unsupported_claims(answer, context)
            if unsupported:
                safety.log_safety_event(safety.EVENT_HALLUCINATION_DETECTED, surface="academic_chat", generation_method="generative", reason="unsupported_claim", claims=len(unsupported))
                return self._extractive(request.question, context) | base

            citations = safety.validate_citations(answer, len(context))
            used = [c for i, c in enumerate(context, start=1) if i in citations["valid"]] if citations["cited"] else context
            safety_info["citation_validation"] = "passed" if citations["cited"] else "uncited"
            return base | {
                "answer": answer,
                "sources": [self._source(c) for c in used],
                "grounded": True,
                "evidence_status": EVIDENCE_SUFFICIENT,
                "generation_method": "generative",
                "model": self.generation.model_name,
                "used_count": len(used),
            }

        return self._extractive(request.question, context) | base

    # ---------------------------------------------------------------- helpers

    @staticmethod
    def _insufficient(model: str, method: str) -> Dict[str, Any]:
        return {
            "answer": INSUFFICIENT_EVIDENCE_TEXT,
            "sources": [],
            "grounded": False,
            "evidence_status": EVIDENCE_INSUFFICIENT,
            "generation_method": method,
            "model": model,
            "used_count": 0,
        }

    def _conflicting(self, context: List[Dict[str, Any]], conflicts: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Show every disagreeing statement with its source; never pick a winner."""
        index = {c["chunk_id"]: i for i, c in enumerate(context, start=1)}
        lines: List[str] = []
        used_ids: List[int] = []
        for conflict in conflicts:
            for entry in conflict["values"]:
                n = index.get(entry["chunk_id"])
                unit = f" {entry['unit']}" if entry.get("unit") else ""
                value = int(entry["value"]) if float(entry["value"]).is_integer() else entry["value"]
                lines.append(f"• {entry.get('document_name') or 'Document'}: {conflict['subject']} = {value}{unit} [S{n}]")
                if entry["chunk_id"] not in used_ids:
                    used_ids.append(entry["chunk_id"])
        used = [c for c in context if c["chunk_id"] in used_ids]
        return {
            "answer": safety.CONFLICTING_EVIDENCE_TEXT + "\n" + "\n".join(lines),
            "sources": [self._source(c) for c in used],
            "grounded": True,
            "evidence_status": EVIDENCE_CONFLICTING,
            "generation_method": "conflicting_evidence",
            "model": ENGINE_NAME,
            "used_count": len(used),
        }

    # Words that only point at the material ("what does this document say?") and give a question no subject of its own
    META_WORDS = {"document", "documents", "doc", "file", "files", "paper", "papers", "say", "says", "tell", "tells", "describe",
                  "summary", "summarize", "summarise", "overview", "mention", "mentions", "cover", "covers", "covered", "content",
                  "contents", "chapter", "chapters", "material", "materials", "note", "notes", "lecture", "lectures", "slide", "slides",
                  "course", "main", "key", "point", "points", "topic", "topics", "about"}

    @classmethod
    def has_specific_subject(cls, question: str) -> bool:
        if is_overview_question(question):
            return False
        words = {w.lower() for w in re.findall(r"[A-Za-z][A-Za-z\-]{2,}", question)}
        return bool(_stems(" ".join(w for w in words if w not in cls.META_WORDS)))

    CLAIM_SUPPORT = 0.65
    CLAIM_MIN_WORDS = 4

    @classmethod
    def unsupported_claims(cls, answer: str, context: List[Dict[str, Any]], verifier: Any = None) -> List[str]:
        """Answer sentences that the documents do not support.

        Word check: a claim's key words must mostly be found in one passage (1-3 adjacent sentences) of the context,
        not scattered across the documents, because course documents share vocabulary: an invented sentence can reuse
        words from all over a syllabus but not the words of one statement.

        Meaning check (claim_check): a claim that a close passage contradicts is unsupported even when its words match
        (one swapped fact, a wrong number); a claim the passages entail, or that is very close in meaning to one, is
        supported even when it is written in other words. Without the meaning model, the word check alone decides.
        With the hosted provider selected, a hosted judge's verdict per claim decides instead (more accurate on
        rewording, numbers and invented details); if it is unavailable, the local checks decide.
        """
        texts: List[str] = []
        passages: List[set] = []
        for c in context:
            sents = [s.strip() for s in re.split(r"(?<=[.!?;:])\s+|\n+", str(c.get("content") or "")) if s.strip()]
            stems = [_stems(s) for s in sents]
            passages.extend(stems)
            passages.extend(stems[i] | stems[i + 1] for i in range(len(stems) - 1))
            passages.extend(stems[i] | stems[i + 1] | stems[i + 2] for i in range(len(stems) - 2))
            # The whole passage too: a summary of a list ("three outcomes: SQL, schemas, isolation") spans its lines
            passages.append(set().union(*stems) if stems else set())
            texts.extend(sents)
            texts.extend(f"{sents[i]} {sents[i + 1]}" for i in range(len(sents) - 1))
        if not passages:
            return []
        text = re.sub(r"\[S\d+\]", " ", answer)
        abbr = abbreviations([str(c.get("content") or "") for c in context])
        claims: List[tuple] = []  # (sentence as written, sentence with abbreviations spelled out, word check passed)
        for sentence in re.split(r"(?<=[.!?])\s+|\n+", text):
            sentence = sentence.strip(" •*-\t")
            full = expand(sentence, abbr)
            words = _stems(full)
            if len(words) < cls.CLAIM_MIN_WORDS:
                continue  # headings, short connectives
            claims.append((sentence, full, max(len(words & p) / len(words) for p in passages) >= cls.CLAIM_SUPPORT))
        if not claims:
            return []

        verdicts = judge_with_llm([full for _, full, _ in claims], [str(c.get("content") or "") for c in context])
        verifier = verifier if verifier is not None else get_claim_verifier()
        # The local check is only needed when there is no hosted verdict
        judgements = verifier.judge([full for _, full, _ in claims], list(dict.fromkeys(texts))[:400]) if texts and verdicts is None else None
        out: List[str] = []
        for i, (sentence, _, lexical_ok) in enumerate(claims):
            if verdicts is not None:
                if verdicts[i] != "SUPPORTED":
                    out.append(sentence)
                continue
            j = judgements[i] if judgements else None
            if not (j.supported(lexical_ok) if j is not None else lexical_ok):
                out.append(sentence)
        return out

    @staticmethod
    def _reject_reason(answer: str, context_size: int) -> str | None:
        """Why a generated answer must not be shown as-is (None → acceptable)."""
        if safety.validate_citations(answer, context_size)["fabricated"]:
            return safety.EVENT_CITATION_VALIDATION_FAILED
        if safety.find_hallucinated_confidence(answer) or safety.find_unsupported_certainty(answer):
            return safety.EVENT_HALLUCINATION_DETECTED
        return None

    def _extractive(self, question: str, context: List[Dict[str, Any]]) -> Dict[str, Any]:
        """Evidence-only answer: synthesize the most relevant passages with [S#] citations."""
        sentences = best_sentences(question, context, limit=6)
        is_overview = is_overview_question(question)

        # Require at least one sentence that lexically overlaps the question or overview context;
        # if completely unrelated without overlap, report insufficient evidence to prevent hallucinations.
        if not sentences:
            return self._insufficient(ENGINE_NAME, "insufficient_evidence")
        if is_overview and not overview_subject_supported(question, context):
            # "What does the document say about quantum computing?" is overview-phrased, but its subject is
            # absent from every document → decline instead of listing unrelated topics.
            return self._insufficient(ENGINE_NAME, "insufficient_evidence")
        if not is_overview and all(s["overlap"] == 0 for s in sentences):
            return self._insufficient(ENGINE_NAME, "insufficient_evidence")

        index = {c["chunk_id"]: i for i, c in enumerate(context, start=1)}
        lines = []
        used_ids: List[int] = []
        top_overlap = max((s["overlap"] for s in sentences), default=0)
        for s in sentences:
            if not is_overview and s["overlap"] == 0 and any(item["overlap"] > 0 for item in sentences):
                continue
            # A line matching far fewer question words than the best one is usually an unrelated line of the same
            # document (e.g. the sorting week under a hashing question)
            if not is_overview and top_overlap >= 3 and s["overlap"] * 2 < top_overlap:
                continue
            n = index.get(s["chunk_id"])
            cleaned_s = self._strip_leaks(s["sentence"])
            lines.append(f"• {cleaned_s} [S{n}]")
            if s["chunk_id"] not in used_ids:
                used_ids.append(s["chunk_id"])

        if not lines:
            return self._insufficient(ENGINE_NAME, "insufficient_evidence")

        used = [c for c in context if c["chunk_id"] in used_ids]
        if not used:
            used = context[:1]

        if is_overview:
            heading = "**Document Overview & Key Topics:**\nBased on the uploaded document material, here are the key sections and topics covered:\n"
        else:
            heading = "**Relevant Document Information:**\nBased on the uploaded course material:\n"

        answer = f"{heading}\n" + "\n".join(lines)
        return {
            "answer": answer,
            "sources": [self._source(c) for c in used],
            "grounded": True,
            "evidence_status": EVIDENCE_SUFFICIENT,
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
        return len(t) >= 8 and bool(re.search(r"[A-Za-z]", t)) and not _LEAK_PATTERNS.search(t) and not safety.contains_secret_leak(t)

    @staticmethod
    def _strip_leaks(text: str) -> str:
        text = _LEAK_PATTERNS.sub("[redacted]", text)
        return safety.SECRET_LEAK_PATTERNS.sub("[redacted]", text).strip()
