"""STEP 33: Constrained Question Generator.

Pipeline per request:
  constraints (+ optional blueprint) → slots → draft questions → validation → structured response.

Drafting uses the configured Hugging Face generation model (GenerationService, HF_GENERATION_MODEL) when
available; its output must be a JSON array and is validated field by field. When no model is configured or
its output is unusable, a deterministic constraint-driven template engine drafts questions from the topic,
course outcome and retrieved document sentences. MiniLM is used ONLY for embeddings (CO alignment and
similarity) — never for text generation.

Every draft is validated in-process with the STEP 10 analyzers (type / difficulty / Bloom), STEP 11 CO
alignment (cosine vs outcome description) and STEP 12 similarity against existing questions. Results are
advisory: faculty review every draft.
"""

import json
import logging
import re
from typing import Any, Dict, List, Optional, Sequence, Tuple

from app.config import get_settings
from app.schemas.question_generation import (
    COGNITIVE_LEVELS,
    DIFFICULTY_LEVELS,
    QUESTION_TYPES,
    GenerateQuestionsRequest,
)
from app.services.cognitive_analyzer import CognitiveAnalyzer
from app.services.difficulty_analyzer import DifficultyAnalyzer
from app.services.generation_service import get_generation_service
from app.services.huggingface_service import get_hf_service
from app.services.prompt_builder import PromptBuilder
from app.services.question_classifier import QuestionClassifier
from app.services.question_generation_prompt import PROMPT_VERSION, build_prompt
from app.services.retrieval import best_sentences, cosine_similarity, key_terms
from app.services.topic_detector import TopicDetector

logger = logging.getLogger("facultylens.ai")

TEMPLATE_ENGINE_NAME = "facultylens-constrained-question-template-engine"
ENGINE_VERSION = "1.0.0"
DISCLAIMER = (
    "AI-generated questions are drafts. Review the wording, factual accuracy, difficulty, cognitive demand, "
    "course-outcome alignment, marks, and academic appropriateness before using them in an assessment."
)

# Detected type that satisfies a requested type (open-response families overlap in the STEP 10 taxonomy).
TYPE_FAMILIES: Dict[str, set] = {
    "MCQ": {"MCQ"},
    "TRUE_FALSE": {"TRUE_FALSE"},
    "PROBLEM_SOLVING": {"PROBLEM_SOLVING"},
    "ANALYTICAL": {"ANALYTICAL"},
    "DESCRIPTIVE": {"DESCRIPTIVE", "ANALYTICAL", "CONCEPTUAL"},
    "SHORT_ANSWER": {"SHORT_ANSWER", "CONCEPTUAL"},
    "CONCEPTUAL": {"CONCEPTUAL", "SHORT_ANSWER"},
}

# Leading verbs recognised by CognitiveAnalyzer for each Bloom level.
_STEMS: Dict[str, List[str]] = {
    "REMEMBER": [
        "Define {topic} as introduced in {course}.",
        "State the key characteristics of {topic}.",
        "List the main components of {topic} covered in {course}.",
    ],
    "UNDERSTAND": [
        "Explain the purpose of {topic} in the context of {course}.",
        "Describe how {topic} works{evidence}.",
        "Discuss the significance of {topic} for {aspect}.",
    ],
    "APPLY": [
        "Apply {topic} to the following situation and show each step{evidence}.",
        "Demonstrate how {topic} can be applied to {aspect}.",
        "Solve the following problem using {topic}{evidence}.",
    ],
    "ANALYZE": [
        "Analyze how {topic} affects {aspect}, and discuss the trade-offs involved.",
        "Compare {topic} with an alternative approach for {aspect}.",
        "Examine the following statement about {topic} and identify its weaknesses{evidence}.",
    ],
    "EVALUATE": [
        "Evaluate the effectiveness of {topic} for {aspect}.",
        "Justify whether {topic} is the appropriate choice for {aspect}.",
        "Critique the following claim about {topic}{evidence}.",
    ],
    "CREATE": [
        "Design a solution based on {topic} for {aspect}.",
        "Propose an improved approach to {topic} for {course}.",
        "Formulate a plan that applies {topic} to {aspect}.",
    ],
}
_MCQ_VERB = {"REMEMBER": "define", "UNDERSTAND": "explain", "APPLY": "apply", "ANALYZE": "analyze",
             "EVALUATE": "evaluate", "CREATE": "propose a design for"}
_DIFFICULTY_SUFFIX = {
    "EASY": "",
    "MEDIUM": " Give a suitable example.",
    "HARD": " Consider a complex, realistic scenario; give detailed reasoning and note the limitations of your answer.",
}
# Outcome/action verbs that make poor "aspects" ("...affects analyze").
_ASPECT_STOP = {"analyze", "analyse", "identify", "apply", "explain", "evaluate", "design", "understand", "demonstrate", "describe",
                "develop", "use", "using", "students", "student", "able", "course", "unit", "lecture", "chapter", "issues", "following"}

_LEAK = re.compile(r"(system prompt|api[_ ]?key|hf_token|password|secret|<<<|>>>|ignore (all|any|the)? ?(previous|prior))", re.I)
# Document sentences that read like instructions are never used as question evidence.
_UNSAFE_EVIDENCE = re.compile(
    r"(\bignore\b.*\b(instruction|requirement|rule|constraint)|\b(reveal|expose|print|output)\b.*\b(prompt|key|token|credential|password)|"
    r"\bgenerate\b.*\b(password|credential|key)|system prompt|api[_ ]?key|you are now|disregard)", re.I)
_DEFAULT_ASPECTS = ["a realistic academic case study", "system design decisions", "performance and correctness requirements", "real-world applications"]


class QuestionGenerator:
    def __init__(self, generation_service: Any = None, hf_service: Any = None) -> None:
        self.settings = get_settings()
        self.generation = generation_service if generation_service is not None else get_generation_service()
        self.hf = hf_service if hf_service is not None else get_hf_service()
        self.topic_detector = TopicDetector(hf_service=self.hf)

    # ------------------------------------------------------------------ public
    def generate(self, request: GenerateQuestionsRequest) -> Dict[str, Any]:
        slots = self._slots(request)
        warnings: List[str] = []

        drafts, method = self._draft(request, slots, warnings)
        drafts = drafts[: len(slots)]
        if len(drafts) < len(slots):
            warnings.append(f"Only {len(drafts)} of {len(slots)} requested questions could be generated.")

        validated = self._validate_all(request, drafts)

        model = self.generation.model_name if method == "generative" else TEMPLATE_ENGINE_NAME
        return {
            "status": "success",
            "questions": validated,
            "generation_method": method,
            "model": model or TEMPLATE_ENGINE_NAME,
            "model_version": ENGINE_VERSION if method == "template" else (model or "unknown"),
            "embedding_model": getattr(self.hf, "model_name", self.settings.hf_model_name),
            "prompt_version": PROMPT_VERSION,
            "requested_count": len(slots),
            "generated_count": len(validated),
            "blueprint_summary": self._blueprint_summary(request, slots, validated) if request.blueprint else None,
            "warnings": warnings,
            "disclaimer": DISCLAIMER,
        }

    # ------------------------------------------------------------------ slots
    @staticmethod
    def _slots(request: GenerateQuestionsRequest) -> List[Dict[str, Any]]:
        if request.blueprint:
            slots = []
            for slot in request.blueprint:
                for _ in range(slot.count):
                    slots.append({
                        "difficulty_level": slot.difficulty_level or request.difficulty_level,
                        "cognitive_level": slot.cognitive_level or request.cognitive_level,
                        "question_type": slot.question_type or request.question_type,
                        "marks": slot.marks or request.marks,
                    })
            return slots[:20]
        return [{
            "difficulty_level": request.difficulty_level,
            "cognitive_level": request.cognitive_level,
            "question_type": request.question_type,
            "marks": request.marks,
        } for _ in range(request.number_of_questions)]

    # ------------------------------------------------------------------ drafting
    def _draft(self, request: GenerateQuestionsRequest, slots: List[Dict[str, Any]], warnings: List[str]) -> Tuple[List[Dict[str, Any]], str]:
        if getattr(self.generation, "is_configured", False):
            drafts = self._draft_generative(request, slots, warnings)
            if len(drafts) >= max(1, len(slots) // 2):
                if len(drafts) < len(slots):
                    drafts += self._draft_template(request, slots[len(drafts):])
                    warnings.append("Some drafts were completed by the template engine because the generation model returned too few valid questions.")
                return drafts, "generative"
            warnings.append("The generation model did not return usable JSON; the template engine was used instead.")
        return self._draft_template(request, slots), "template"

    def _draft_generative(self, request: GenerateQuestionsRequest, slots: List[Dict[str, Any]], warnings: List[str]) -> List[Dict[str, Any]]:
        drafts: List[Dict[str, Any]] = []
        # Group identical slots so one prompt asks for N questions of the same spec.
        groups: Dict[Tuple, List[Dict[str, Any]]] = {}
        for slot in slots:
            groups.setdefault((slot["difficulty_level"], slot["cognitive_level"], slot["question_type"], slot["marks"]), []).append(slot)

        for (diff, cog, qtype, marks), group in groups.items():
            prompt = build_prompt(request, slot_difficulty=diff, slot_cognitive=cog, slot_type=qtype, slot_marks=marks, count=len(group))
            try:
                raw = self.generation.generate(prompt, max_new_tokens=self.settings.question_generation_max_new_tokens)
            except Exception as exc:  # never let the model crash the request
                logger.warning("Question generation model failed: %s", exc)
                raw = None
            items = self._parse_json_array(raw or "")
            for item in items[: len(group)]:
                norm = self._normalize_item(item, request, group[0])
                if norm:
                    drafts.append(norm)
        return drafts

    @staticmethod
    def _parse_json_array(text: str) -> List[Dict[str, Any]]:
        text = text.strip()
        if not text:
            return []
        candidates = [text]
        start, end = text.find("["), text.rfind("]")
        if start != -1 and end > start:
            candidates.append(text[start:end + 1])
        for cand in candidates:
            try:
                data = json.loads(cand)
            except json.JSONDecodeError:
                continue
            if isinstance(data, dict):
                data = data.get("questions", [data])
            if isinstance(data, list):
                return [d for d in data if isinstance(d, dict)]
        return []

    def _normalize_item(self, item: Dict[str, Any], request: GenerateQuestionsRequest, slot: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        text = str(item.get("question_text") or item.get("question") or "").strip()
        if len(text) < 10 or _LEAK.search(text):
            return None
        qtype = str(item.get("question_type") or slot["question_type"]).upper().replace(" ", "_")
        if qtype not in QUESTION_TYPES:
            qtype = slot["question_type"]
        diff = str(item.get("difficulty_level") or slot["difficulty_level"] or "").upper() or None
        cog = str(item.get("cognitive_level") or slot["cognitive_level"] or "").upper() or None
        try:
            marks = float(item.get("marks") or slot["marks"])
        except (TypeError, ValueError):
            marks = float(slot["marks"])
        if marks <= 0:
            marks = float(slot["marks"])
        options = item.get("options") if isinstance(item.get("options"), list) else None
        if options:
            options = [str(o).strip()[:500] for o in options if str(o).strip()][:6]
        return {
            "question_text": text[:5000],
            "question_type": qtype,
            "marks": marks,
            "difficulty_level": diff if diff in DIFFICULTY_LEVELS else slot["difficulty_level"],
            "cognitive_level": cog if cog in COGNITIVE_LEVELS else slot["cognitive_level"],
            "topic": (str(item.get("topic") or request.topic or "").strip() or None),
            "options": options if qtype == "MCQ" else None,
            "correct_option": (str(item["correct_option"]).strip()[:500] if item.get("correct_option") else None),
            "expected_answer": (str(item["expected_answer"]).strip()[:5000] if request.include_expected_answer and item.get("expected_answer") else None),
            "explanation": (str(item["explanation"]).strip()[:2000] if request.include_explanation and item.get("explanation") else None),
            "source_chunk_ids": [c.chunk_id for c in request.document_context if c.chunk_id is not None][:5],
        }

    # ------------------------------------------------------------------ template engine
    def _draft_template(self, request: GenerateQuestionsRequest, slots: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        cc = request.course_context
        course = " — ".join(p for p in [cc.course_code, cc.course_name] if p) or "this course"
        lo_desc = request.learning_outcome.description if request.learning_outcome else ""
        topic = request.topic or self._topic_from_outcome(lo_desc) or "the course topic"

        chunks = [{"chunk_id": c.chunk_id, "content": c.content, "document_name": c.document_name} for c in request.document_context]
        evidence = best_sentences(f"{topic} {lo_desc}", chunks, limit=max(4, len(slots))) if chunks else []
        evidence = [e for e in evidence if not PromptBuilder.contains_injection(e["sentence"]) and not _UNSAFE_EVIDENCE.search(e["sentence"])]
        aspects = self._aspects(f"{lo_desc} {' '.join(e['sentence'] for e in evidence)}", topic)

        drafts: List[Dict[str, Any]] = []
        for i, slot in enumerate(slots):
            cog = slot["cognitive_level"] or (request.learning_outcome.cognitive_level.upper() if request.learning_outcome and request.learning_outcome.cognitive_level else None) or "UNDERSTAND"
            cog = cog if cog in _STEMS else "UNDERSTAND"
            diff = slot["difficulty_level"] or "MEDIUM"
            qtype = slot["question_type"]
            ev = evidence[i % len(evidence)] if evidence else None
            aspect = aspects[i % len(aspects)]
            ev_clause = f": \"{ev['sentence'].rstrip('.')}\"" if ev else ""

            stem = _STEMS[cog][i % len(_STEMS[cog])].format(topic=topic, course=course, aspect=aspect, evidence=ev_clause)
            text, options, correct = self._shape_type(stem, qtype, cog, topic, course, ev, aspect)
            text = (text.rstrip() + _DIFFICULTY_SUFFIX.get(diff, "").format(course=course)).strip()

            drafts.append({
                "question_text": text,
                "question_type": qtype,
                "marks": float(slot["marks"]),
                "difficulty_level": diff,
                "cognitive_level": cog,
                "topic": topic,
                "options": options,
                "correct_option": correct,
                "expected_answer": self._expected_answer(request, topic, cog, ev, lo_desc) if request.include_expected_answer else None,
                "explanation": (f"Drafted by the template engine to target {cog.title()} ({diff.lower()}) on '{topic}'"
                                + (f", grounded in {ev['document_name']}" if ev else "") + ".") if request.include_explanation else None,
                "source_chunk_ids": [ev["chunk_id"]] if ev and ev.get("chunk_id") is not None else [],
            })
        return drafts

    @staticmethod
    def _aspects(text: str, topic: str) -> List[str]:
        """Noun-ish phrases to vary stems: bigrams of consecutive key terms first, then longer single terms."""
        words = [w for w in re.findall(r"[a-z0-9-]+", text.lower())]
        ok = lambda w: w not in _ASPECT_STOP and len(w) > 2 and w in set(key_terms(w)) and w not in topic.lower()
        phrases: List[str] = []
        for a, b in zip(words, words[1:]):
            if ok(a) and ok(b) and f"{a} {b}" not in phrases:
                phrases.append(f"{a} {b}")
        singles = [w for w in dict.fromkeys(words) if ok(w) and len(w) >= 6 and not any(w in p for p in phrases)]
        return (phrases + singles)[:8] or list(_DEFAULT_ASPECTS)

    @staticmethod
    def _shape_type(stem: str, qtype: str, cog: str, topic: str, course: str, ev: Optional[Dict[str, Any]], aspect: str):
        if qtype == "MCQ":
            correct = ev["sentence"] if ev else f"{topic} is a core concept examined in {course}."
            options = [correct,
                       f"{topic} is unrelated to {aspect}.",
                       f"{topic} can be ignored when working with {aspect}.",
                       f"{topic} applies only outside the scope of {course}."]
            return (f"Which of the following statements correctly {_MCQ_VERB[cog]} {topic}? Choose the correct option.", options, correct)
        if qtype == "TRUE_FALSE":
            statement = ev["sentence"] if ev else f"{topic} is a fundamental concept in {course}."
            tail = "" if cog in ("REMEMBER", "UNDERSTAND") else f" Then {_MCQ_VERB[cog]} the reasoning behind your answer."
            return (f"True or False: {statement.rstrip('.')}.{tail}", None, "True")
        if qtype == "SHORT_ANSWER":
            return (f"In brief words, {stem[0].lower() + stem[1:]}", None, None)
        if qtype == "PROBLEM_SOLVING" and not QuestionClassifier.PROBLEM_SOLVING_PATTERN.search(stem):
            return (f"{stem.rstrip('.')}, then solve a concrete example step by step.", None, None)
        return (stem, None, None)

    @staticmethod
    def _expected_answer(request: GenerateQuestionsRequest, topic: str, cog: str, ev: Optional[Dict[str, Any]], lo_desc: str) -> str:
        parts = [f"A complete answer should address {topic}"]
        if lo_desc:
            parts.append(f"in line with the course outcome: {lo_desc.rstrip('.')}")
        if ev:
            parts.append(f"and draw on the course material, e.g. \"{ev['sentence'].rstrip('.')}\"")
        parts.append(f"at the {cog.title()} level of Bloom's taxonomy. (Draft expected answer — faculty must refine before use.)")
        return " ".join(parts)

    @staticmethod
    def _topic_from_outcome(lo_desc: str) -> Optional[str]:
        terms = key_terms(lo_desc)
        return " ".join(terms[:2]) if terms else None

    # ------------------------------------------------------------------ validation
    def _validate_all(self, request: GenerateQuestionsRequest, drafts: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        if not drafts:
            return []
        sim_t = {"duplicate": self.settings.similarity_duplicate_threshold, "high": self.settings.similarity_high_threshold,
                 "moderate": self.settings.similarity_moderate_threshold}
        sim_t.update({k: float(v) for k, v in (request.similarity_thresholds or {}).items() if k in sim_t})
        ali_t = {"strong": self.settings.question_generation_alignment_strong, "weak": self.settings.question_generation_alignment_weak}
        ali_t.update({k: float(v) for k, v in (request.alignment_thresholds or {}).items() if k in ali_t})

        texts = [d["question_text"] for d in drafts]
        existing = request.existing_question_context
        lo_text = request.learning_outcome.description if request.learning_outcome else None
        to_embed = texts + [q.text for q in existing] + ([lo_text] if lo_text else [])
        vectors = self._embed(to_embed)
        q_vecs = vectors[: len(texts)]
        ex_vecs = vectors[len(texts): len(texts) + len(existing)]
        lo_vec = vectors[-1] if lo_text and vectors else None

        out: List[Dict[str, Any]] = []
        for i, draft in enumerate(drafts):
            out.append(self._validate_one(request, draft, q_vecs[i] if q_vecs else None, ex_vecs, lo_vec, q_vecs, i, sim_t, ali_t))
        return out

    def _embed(self, texts: List[str]) -> List[List[float]]:
        if not texts:
            return []
        try:
            return [list(v) for v in self.hf.generate_batch_embeddings(texts)]
        except Exception as exc:
            logger.warning("Embedding failed during question validation: %s", exc)
            return []

    def _validate_one(self, request, draft, q_vec, ex_vecs, lo_vec, all_q_vecs, idx, sim_t, ali_t) -> Dict[str, Any]:
        text = draft["question_text"]
        warnings: List[str] = []
        detected_type = QuestionClassifier.classify(text)["question_type"]
        detected_diff = DifficultyAnalyzer.analyze(text)["level"]
        detected_cog = CognitiveAnalyzer.analyze(text)["level"]
        try:
            topics = [t["name"] for t in self.topic_detector.detect_topics(text, course_topics=[request.topic] if request.topic else None)]
        except Exception:
            topics = []

        checks: Dict[str, Any] = {"marks": draft["marks"] > 0}
        checks["question_type"] = detected_type in TYPE_FAMILIES.get(draft["question_type"], {draft["question_type"]})
        if not checks["question_type"]:
            warnings.append(f"Question-type mismatch: requested {draft['question_type']}, AI-detected {detected_type}.")

        req_diff = draft.get("difficulty_level")
        checks["difficulty"] = (detected_diff == req_diff) if req_diff else None
        if checks["difficulty"] is False:
            warnings.append(f"Difficulty mismatch: requested {req_diff}, AI-estimated {detected_diff}.")

        req_cog = draft.get("cognitive_level")
        checks["cognitive_level"] = (detected_cog == req_cog) if req_cog else None
        if checks["cognitive_level"] is False:
            warnings.append(f"Cognitive-level mismatch: requested {req_cog}, AI-detected {detected_cog}.")

        # Topic: requested topic terms must appear in the question.
        if request.topic:
            terms = [t.lower() for t in key_terms(request.topic)] or [request.topic.lower()]
            checks["topic"] = any(t in text.lower() for t in terms)
            if not checks["topic"]:
                warnings.append(f"The question does not mention the requested topic '{request.topic}'.")
        else:
            checks["topic"] = None

        # CO alignment (STEP 11 thresholds)
        align_score = align_status = None
        if lo_vec is not None and q_vec is not None:
            align_score = round(cosine_similarity(q_vec, lo_vec), 4)
            align_status = "STRONG" if align_score >= ali_t["strong"] else "WEAK" if align_score >= ali_t["weak"] else "NOT_ALIGNED"
            checks["co_alignment"] = align_status == "STRONG"
            if align_status == "WEAK":
                warnings.append(f"CO alignment is weak ({align_score:.2f}).")
            elif align_status == "NOT_ALIGNED":
                warnings.append(f"The question does not appear aligned with the selected course outcome ({align_score:.2f}).")
        else:
            checks["co_alignment"] = None

        # Similarity vs existing questions (STEP 12 thresholds); near-duplicates inside the generated set only warn.
        matches: List[Dict[str, Any]] = []
        set_dupes: List[str] = []
        if q_vec is not None:
            for ex, vec in zip(request.existing_question_context, ex_vecs):
                score = cosine_similarity(q_vec, vec)
                if score >= sim_t["moderate"]:
                    matches.append({"existing_id": ex.id, "source": ex.source, "label": ex.label, "text": ex.text[:300],
                                    "similarity_score": round(score, 4), "status": self._sim_status(score, sim_t)})
            for j, vec in enumerate(all_q_vecs):
                if j != idx and cosine_similarity(q_vec, vec) >= sim_t["duplicate"]:
                    set_dupes.append(f"Draft {j + 1}")
        matches.sort(key=lambda m: m["similarity_score"], reverse=True)
        matches = matches[:5]
        max_sim = matches[0]["similarity_score"] if matches else 0.0
        sim_status = self._sim_status(max_sim, sim_t)
        checks["similarity"] = sim_status not in ("POTENTIAL_DUPLICATE", "HIGHLY_SIMILAR") and not set_dupes
        if sim_status == "POTENTIAL_DUPLICATE":
            warnings.append(f"Potential duplicate of an existing question (similarity {max_sim:.2f}). Faculty review required.")
        elif sim_status == "HIGHLY_SIMILAR":
            warnings.append(f"Highly similar to an existing question (similarity {max_sim:.2f}).")
        if set_dupes:
            warnings.append(f"Very similar to {', '.join(set_dupes)} in this generated set; consider keeping only one.")

        if draft["question_type"] == "MCQ" and (not draft.get("options") or len(draft["options"]) < 2):
            warnings.append("MCQ draft has fewer than two options; add options before use.")
        if draft["question_type"] == "MCQ" and draft.get("options") and self.generation_method_is_template():
            warnings.append("MCQ distractors were drafted by the template engine and need faculty review.")

        overall = "PASSED"
        if not checks["marks"] or checks["topic"] is False or sim_status == "POTENTIAL_DUPLICATE" or align_status == "NOT_ALIGNED":
            overall = "FAILED"
        elif warnings:
            overall = "PASSED_WITH_WARNINGS"

        validation = {
            "detected_question_type": detected_type,
            "detected_difficulty": detected_diff,
            "detected_cognitive_level": detected_cog,
            "detected_topics": topics[:5],
            "co_alignment_score": align_score,
            "co_alignment_status": align_status,
            "max_similarity_score": round(max_sim, 4),
            "similarity_status": sim_status,
            "similar_questions": matches,
            "constraints": checks,
            "warnings": warnings,
            "overall_status": overall,
        }
        return {**draft, "validation": validation}

    def generation_method_is_template(self) -> bool:
        return not getattr(self.generation, "is_configured", False)

    @staticmethod
    def _sim_status(score: float, t: Dict[str, float]) -> str:
        if score >= t["duplicate"]:
            return "POTENTIAL_DUPLICATE"
        if score >= t["high"]:
            return "HIGHLY_SIMILAR"
        if score >= t["moderate"]:
            return "SOMEWHAT_SIMILAR"
        return "NOT_SIMILAR"

    # ------------------------------------------------------------------ blueprint
    @staticmethod
    def _blueprint_summary(request: GenerateQuestionsRequest, slots: List[Dict[str, Any]], generated: List[Dict[str, Any]]) -> Dict[str, Any]:
        def dist(items: Sequence[Dict[str, Any]], key_req: str, key_det: str) -> Tuple[Dict[str, int], Dict[str, int]]:
            req: Dict[str, int] = {}
            det: Dict[str, int] = {}
            for s in slots:
                if s.get(key_req):
                    req[s[key_req]] = req.get(s[key_req], 0) + 1
            for g in items:
                v = g["validation"].get(key_det)
                if v:
                    det[v] = det.get(v, 0) + 1
            return req, det

        req_d, det_d = dist(generated, "difficulty_level", "detected_difficulty")
        req_c, det_c = dist(generated, "cognitive_level", "detected_cognitive_level")
        return {
            "requested": {"difficulty": req_d, "cognitive_level": req_c},
            "generated": {"difficulty": det_d, "cognitive_level": det_c},
            "matches": req_d == det_d and req_c == det_c,
        }
