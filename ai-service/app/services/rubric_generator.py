"""AI Rubric Generator (STEP 25).

Produces a structured DRAFT grading rubric for a single assessment question.

Generation strategy
-------------------
1. Structured template engine (always available, deterministic):
   - extracts the tasks the question asks for (verbs + objects + enumerations)
   - applies question-type specific criterion templates
   - distributes marks so they sum EXACTLY to the question total
2. Optional generative Hugging Face seq2seq model (TextGenerationService):
   - when configured, proposes criterion names for the draft
   - its output is parsed, sanitized and validated; on any problem the
     template engine result is used instead
3. MiniLM embeddings (HuggingFaceService) are used only for what an encoder
   is good at: ranking / de-duplicating candidate criteria by semantic
   relevance to the question and learning outcome. MiniLM never writes text.

The output is a suggestion for faculty review. It is never marked final.
"""

import logging
import math
import re
from typing import Any, Dict, List, Optional, Tuple

from app.config import get_settings
from app.schemas.rubric import (
    GenerateRubricRequest,
    RubricGenerationMethod,
    RubricQuestionType,
)
from app.services.huggingface_service import HuggingFaceService
from app.services.rubric_validator import RubricValidator
from app.services.text_generation_service import TextGenerationService

logger = logging.getLogger("facultylens.ai")

TEMPLATE_ENGINE_NAME = "facultylens-rubric-template-engine"
ENGINE_VERSION = "1.0.0"

# verb -> criterion label prefix
ACTION_VERBS: Dict[str, str] = {
    "explain": "Explanation of",
    "describe": "Description of",
    "define": "Definition of",
    "discuss": "Discussion of",
    "compare": "Comparison of",
    "contrast": "Contrast of",
    "differentiate": "Differentiation of",
    "distinguish": "Distinction between",
    "analyze": "Analysis of",
    "analyse": "Analysis of",
    "evaluate": "Evaluation of",
    "assess": "Assessment of",
    "critique": "Critique of",
    "calculate": "Calculation of",
    "compute": "Computation of",
    "derive": "Derivation of",
    "solve": "Solution of",
    "determine": "Determination of",
    "find": "Determination of",
    "identify": "Identification of",
    "list": "Listing of",
    "state": "Statement of",
    "outline": "Outline of",
    "illustrate": "Illustration of",
    "justify": "Justification of",
    "design": "Design of",
    "write": "Writing of",
    "implement": "Implementation of",
    "prove": "Proof of",
    "show": "Demonstration of",
    "draw": "Diagram of",
    "sketch": "Sketch of",
    "summarize": "Summary of",
    "summarise": "Summary of",
    "classify": "Classification of",
    "apply": "Application of",
    "demonstrate": "Demonstration of",
    "construct": "Construction of",
    "develop": "Development of",
    "propose": "Proposal of",
    "recommend": "Recommendation of",
    "interpret": "Interpretation of",
    "elaborate": "Elaboration of",
}

VERB_PATTERN = re.compile(
    r"\b(" + "|".join(sorted(ACTION_VERBS.keys(), key=len, reverse=True)) + r")\b",
    re.IGNORECASE,
)

FILLER_PREFIX = re.compile(
    r"^(?:briefly|clearly|in\s+detail|in\s+brief|with\s+examples?|the\s+process\s+of|the\s+concept\s+of|"
    r"the\s+term|the\s+following|the|a|an|how|what|why|each\s+of|all|any)\s+",
    re.IGNORECASE,
)
FILLER_SUFFIX = re.compile(
    r"\s*(?:with\s+(?:suitable|appropriate|relevant|proper|the\s+help\s+of)?\s*(?:examples?|diagrams?|illustrations?)|"
    r"in\s+detail|briefly|in\s+your\s+own\s+words|giving\s+examples?|using\s+examples?)\s*[\.\?!]*$",
    re.IGNORECASE,
)
ENUM_SPLIT = re.compile(r"\s*(?:,|;|/|&|\band\b|\bor\b)\s*", re.IGNORECASE)
MARKS_HINT = re.compile(r"[\(\[]\s*\d+(?:\.\d+)?\s*(?:marks?|pts?|points?)\s*[\)\]]", re.IGNORECASE)

MAX_LABEL_LENGTH = 90

# verbs whose object is a single relational task and must not be split into separate criteria
RELATIONAL_VERBS = {"compare", "contrast", "differentiate", "distinguish"}


class RubricGenerator:
    """Builds structured draft rubrics for a single assessment question."""

    def __init__(
        self,
        hf_service: Optional[HuggingFaceService] = None,
        text_generation_service: Optional[TextGenerationService] = None,
    ) -> None:
        self.hf_service = hf_service
        self.text_generation_service = text_generation_service
        self.settings = get_settings()

    # ------------------------------------------------------------------ public

    def generate(self, request: GenerateRubricRequest) -> Dict[str, Any]:
        question_text = MARKS_HINT.sub("", request.question_text).strip()
        total_marks = round(float(request.total_marks), 2)
        q_type = request.question_type

        generative_used = False
        candidates: List[Dict[str, Any]] = []

        # 1. Optional generative draft
        if self.text_generation_service is not None and self.text_generation_service.is_configured:
            candidates = self._candidates_from_generative_model(request, question_text)
            generative_used = len(candidates) >= 2

        # 2. Template engine (primary or fallback)
        if not generative_used:
            candidates = self._candidates_from_template(question_text, q_type, request)

        # 3. Rank / de-duplicate with embeddings when available
        embedding_model = None
        candidates, embedding_model = self._rank_candidates(candidates, question_text, request)

        # 4. Respect criteria limits imposed by total marks and configuration
        candidates = self._limit_candidates(candidates, total_marks)

        # 5. Distribute marks exactly
        marks = self._distribute_marks([c["weight"] for c in candidates], total_marks)

        criteria = []
        for idx, (cand, mark) in enumerate(zip(candidates, marks), start=1):
            criteria.append(
                {
                    "criterion": cand["criterion"][:255],
                    "description": cand["description"][:2000],
                    "max_marks": mark,
                    "scoring_guidance": self._scoring_guidance(cand["criterion"], mark),
                    "expected_indicators": cand.get("expected_indicators", [])[:6],
                    "sort_order": idx,
                }
            )

        rubric = {
            "title": self._build_title(question_text, request),
            "question_text": request.question_text,
            "total_marks": total_marks,
            "criteria": criteria,
            "general_guidance": self._general_guidance(request),
        }

        # 6. Never return an inconsistent rubric
        RubricValidator.assert_valid(rubric, total_marks)

        method = (
            RubricGenerationMethod.AI_ASSISTED
            if generative_used
            else RubricGenerationMethod.TEMPLATE_BASED
        )
        model_name = (
            self.text_generation_service.model_name
            if generative_used and self.text_generation_service is not None
            else TEMPLATE_ENGINE_NAME
        )

        return {
            "status": "success",
            "generation_method": method.value,
            "draft_status": "DRAFT",
            "rubric": rubric,
            "metadata": {
                "model": model_name,
                "version": ENGINE_VERSION,
                "embedding_model": embedding_model,
                "generative_model_used": generative_used,
                "criteria_count": len(criteria),
                "validation_passed": True,
            },
        }

    # -------------------------------------------------------- task extraction

    @classmethod
    def extract_tasks(cls, question_text: str) -> List[Dict[str, Any]]:
        """Return list of {verb, label, object, items} describing what the question asks for."""
        text = re.sub(r"\s+", " ", question_text).strip()
        matches = list(VERB_PATTERN.finditer(text))
        tasks: List[Dict[str, Any]] = []
        seen_objects = set()

        for i, match in enumerate(matches):
            verb = match.group(1).lower()
            start = match.end()
            end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
            raw_object = text[start:end]
            # cut at sentence boundary
            raw_object = re.split(r"[\.\?!](?:\s|$)", raw_object)[0]
            raw_object = re.sub(r"\s+(?:and|,|;)\s*$", "", raw_object.strip(" ,;:-"))
            obj = cls._clean_object(raw_object)
            if not obj:
                continue
            items = [] if verb in RELATIONAL_VERBS else cls._split_enumeration(obj)
            key = obj.lower()
            if key in seen_objects:
                continue
            seen_objects.add(key)
            tasks.append(
                {
                    "verb": verb,
                    "label": ACTION_VERBS[verb],
                    "object": obj,
                    "items": items,
                }
            )
        return tasks

    @classmethod
    def _clean_object(cls, obj: str) -> str:
        obj = obj.strip(" ,;:-")
        previous = None
        while previous != obj:
            previous = obj
            obj = FILLER_PREFIX.sub("", obj).strip()
            obj = FILLER_SUFFIX.sub("", obj).strip()
        obj = obj.strip(" ,;:-.?!")
        if len(obj) > MAX_LABEL_LENGTH:
            obj = obj[:MAX_LABEL_LENGTH].rsplit(" ", 1)[0].rstrip(" ,;:-") + "…"
        return obj

    @staticmethod
    def _split_enumeration(obj: str) -> List[str]:
        parts = [p.strip(" .") for p in ENUM_SPLIT.split(obj) if p and p.strip(" .")]
        if len(parts) < 2 or len(parts) > 8:
            return []
        if any(len(p.split()) > 4 for p in parts):
            return []
        return parts

    # --------------------------------------------------------- candidate build

    def _candidates_from_template(
        self,
        question_text: str,
        q_type: RubricQuestionType,
        request: GenerateRubricRequest,
    ) -> List[Dict[str, Any]]:
        lower = question_text.lower()
        wants_examples = bool(re.search(r"\bexamples?\b", lower))
        wants_diagram = bool(re.search(r"\b(diagrams?|figures?|sketch|draw|flowchart)\b", lower))
        wants_justification = bool(re.search(r"\b(justify|justification|why|reason|give reasons?)\b", lower))
        wants_pros_cons = bool(re.search(r"\b(advantages?|disadvantages?|pros|cons|merits?|demerits?|limitations?)\b", lower))
        tasks = self.extract_tasks(question_text)
        topic = self._topic_phrase(question_text, tasks)

        candidates: List[Dict[str, Any]] = []

        if q_type in (RubricQuestionType.MCQ, RubricQuestionType.TRUE_FALSE):
            answer_label = "Correct option selected" if q_type == RubricQuestionType.MCQ else "Correct true/false judgement"
            candidates.append(
                self._cand(
                    answer_label,
                    f"The response identifies the correct answer for: {topic}.",
                    3.0,
                    ["Correct answer indicated", "Only one answer selected" if q_type == RubricQuestionType.MCQ else "Judgement stated unambiguously"],
                )
            )
            if wants_justification or float(request.total_marks) >= 2:
                candidates.append(
                    self._cand(
                        "Justification of the answer",
                        "A brief, accurate reason supporting the selected answer.",
                        2.0,
                        ["Reason is relevant to the question", "Reason is factually correct"],
                    )
                )
            return candidates

        if q_type == RubricQuestionType.PROBLEM_SOLVING:
            candidates.append(
                self._cand(
                    "Correct interpretation of the problem",
                    f"Identifies the given data, unknowns and constraints for: {topic}.",
                    1.5,
                    ["Given values / inputs identified", "Required output stated"],
                )
            )
            for task in tasks:
                candidates.extend(self._task_candidates(task, weight=2.0))
            if not tasks:
                candidates.append(
                    self._cand(
                        "Appropriate method or formula",
                        "Selects and applies a valid method, algorithm or formula.",
                        2.0,
                        ["Correct formula / algorithm chosen", "Method matches the problem"],
                    )
                )
            candidates.append(
                self._cand(
                    "Accuracy of working and steps",
                    "Intermediate steps are shown, logically ordered and free of errors.",
                    2.0,
                    ["Steps are shown", "No arithmetic or logical errors"],
                )
            )
            candidates.append(
                self._cand(
                    "Final answer",
                    "States the final result clearly with appropriate units, form or verification.",
                    1.5,
                    ["Final answer stated", "Units / format correct"],
                )
            )
            return candidates

        if q_type == RubricQuestionType.CONCEPTUAL:
            candidates.append(
                self._cand(
                    f"Definition of {topic}",
                    f"Provides an accurate definition or statement of {topic}.",
                    2.0,
                    ["Accurate definition", "Correct terminology"],
                )
            )
            for task in tasks:
                if task["verb"] not in ("define", "state", "what"):
                    candidates.extend(self._task_candidates(task, weight=2.0))
            candidates.append(
                self._cand(
                    "Key properties or characteristics",
                    f"Mentions the essential properties or characteristics of {topic}.",
                    1.5,
                    ["Core properties listed", "No major misconceptions"],
                )
            )
            if wants_examples:
                candidates.append(self._examples_candidate(topic))
            return candidates

        if q_type == RubricQuestionType.ANALYTICAL:
            candidates.append(
                self._cand(
                    "Identification of key aspects",
                    f"Identifies the relevant factors, components or dimensions of {topic}.",
                    1.5,
                    ["Relevant aspects identified", "Scope of analysis is clear"],
                )
            )
            for task in tasks:
                candidates.extend(self._task_candidates(task, weight=2.0))
            candidates.append(
                self._cand(
                    "Depth and quality of analysis",
                    "Analysis goes beyond description, showing reasoning and relationships.",
                    2.0,
                    ["Cause / effect or relationships explained", "Reasoning is coherent"],
                )
            )
            candidates.append(
                self._cand(
                    "Justified conclusion",
                    "Draws a conclusion that is supported by the preceding analysis.",
                    1.5,
                    ["Conclusion stated", "Conclusion follows from analysis"],
                )
            )
            return candidates

        # SHORT_ANSWER, DESCRIPTIVE, OTHER
        is_short = q_type == RubricQuestionType.SHORT_ANSWER

        if tasks:
            for task in tasks:
                candidates.extend(self._task_candidates(task, weight=2.0))
        else:
            candidates.append(
                self._cand(
                    f"Explanation of {topic}",
                    f"Explains {topic} accurately and addresses what the question asks.",
                    2.0,
                    ["Directly addresses the question", "Accurate content"],
                )
            )

        if wants_pros_cons and not any("advantage" in c["criterion"].lower() for c in candidates):
            candidates.append(
                self._cand(
                    "Advantages and limitations",
                    "Presents relevant advantages and limitations with brief support.",
                    1.5,
                    ["At least one advantage", "At least one limitation"],
                )
            )
        if wants_examples:
            candidates.append(self._examples_candidate(topic))
        if wants_diagram:
            candidates.append(
                self._cand(
                    "Diagram or illustration",
                    "Includes a correctly labelled diagram that supports the explanation.",
                    1.5,
                    ["Diagram present", "Labels are correct"],
                )
            )
        if wants_justification and not any("justif" in c["criterion"].lower() for c in candidates):
            candidates.append(
                self._cand(
                    "Justification and reasoning",
                    "Supports statements with valid reasons.",
                    1.5,
                    ["Reasons are relevant", "Reasoning is correct"],
                )
            )

        candidates.append(
            self._cand(
                "Use of correct terminology" if is_short else "Clarity, organization and terminology",
                "Uses appropriate technical terms accurately."
                if is_short
                else "Response is well organized, coherent and uses appropriate technical terminology.",
                1.0,
                ["Correct technical terms", "Logical structure"],
            )
        )
        return candidates

    def _task_candidates(self, task: Dict[str, Any], weight: float) -> List[Dict[str, Any]]:
        label = task["label"]
        out: List[Dict[str, Any]] = []
        if task["items"]:
            for item in task["items"]:
                out.append(
                    self._cand(
                        f"{label} {item}",
                        f"Correctly {task['verb']}s {item} as required by the question.",
                        weight,
                        [f"{item} addressed", f"Content about {item} is accurate"],
                    )
                )
        else:
            obj = task["object"]
            out.append(
                self._cand(
                    f"{label} {obj}",
                    f"Correctly {task['verb']}s {obj} as required by the question.",
                    weight,
                    [f"{obj} addressed directly", "Key points covered", "Accurate content"],
                )
            )
        return out

    @staticmethod
    def _examples_candidate(topic: str) -> Dict[str, Any]:
        return RubricGenerator._cand(
            "Appropriate examples",
            f"Provides relevant, correct examples that support the explanation of {topic}.",
            1.5,
            ["At least one relevant example", "Example is correct and clearly linked"],
        )

    @staticmethod
    def _cand(criterion: str, description: str, weight: float, indicators: List[str]) -> Dict[str, Any]:
        return {
            "criterion": criterion.strip(),
            "description": description.strip(),
            "weight": weight,
            "expected_indicators": [i[:1].upper() + i[1:] for i in indicators if i],
        }

    @staticmethod
    def _topic_phrase(question_text: str, tasks: List[Dict[str, Any]]) -> str:
        if tasks and tasks[0]["object"]:
            return tasks[0]["object"]
        words = re.sub(r"[^\w\s\-]", " ", question_text).split()
        phrase = " ".join(words[:8]).strip()
        return phrase or "the topic"

    # ----------------------------------------------------- generative support

    def _candidates_from_generative_model(
        self, request: GenerateRubricRequest, question_text: str
    ) -> List[Dict[str, Any]]:
        if self.text_generation_service is None:
            return []
        lo = request.learning_outcome
        lo_text = f"Learning outcome: {lo.code or ''} {lo.description or ''}\n" if lo else ""
        prompt = (
            "Draft grading criteria for a university exam question. "
            "List between 3 and 6 short criteria, one per line, without marks.\n"
            f"Question type: {request.question_type.value}\n"
            f"Total marks: {request.total_marks}\n"
            f"{lo_text}"
            f"Question: {question_text}\n"
            "Criteria:"
        )
        lines = self.text_generation_service.generate_lines(prompt)
        candidates: List[Dict[str, Any]] = []
        seen = set()
        for line in lines:
            name = re.sub(r"^\s*(?:\d+[\.\)]|[-*•])\s*", "", line).strip(" .:-")
            name = re.sub(r"\s+", " ", name)
            if len(name) < 4 or len(name) > 120:
                continue
            if not re.search(r"[a-zA-Z]", name):
                continue
            key = name.lower()
            if key in seen:
                continue
            seen.add(key)
            candidates.append(
                self._cand(
                    name[:1].upper() + name[1:],
                    f"The response addresses: {name}.",
                    2.0,
                    [f"{name} is covered", "Content is accurate"],
                )
            )
            if len(candidates) >= self.settings.rubric_max_criteria:
                break
        return candidates

    # ---------------------------------------------------------------- ranking

    def _rank_candidates(
        self,
        candidates: List[Dict[str, Any]],
        question_text: str,
        request: GenerateRubricRequest,
    ) -> Tuple[List[Dict[str, Any]], Optional[str]]:
        """De-duplicate near-identical criteria using MiniLM embeddings; keep original order otherwise."""
        if self.hf_service is None or len(candidates) < 2:
            return candidates, None
        try:
            texts = [c["criterion"] for c in candidates]
            vectors = self.hf_service.generate_batch_embeddings(texts)
        except Exception as e:  # embedding unavailable -> keep deterministic order
            logger.info(f"Rubric ranking skipped (embeddings unavailable): {e}")
            return candidates, None

        kept: List[Dict[str, Any]] = []
        kept_vectors: List[List[float]] = []
        for cand, vec in zip(candidates, vectors):
            if any(self._cosine(vec, kv) > 0.92 for kv in kept_vectors):
                continue
            kept.append(cand)
            kept_vectors.append(vec)
        return kept, self.hf_service.model_name

    @staticmethod
    def _cosine(a: List[float], b: List[float]) -> float:
        dot = sum(x * y for x, y in zip(a, b))
        na = math.sqrt(sum(x * x for x in a))
        nb = math.sqrt(sum(y * y for y in b))
        if na == 0 or nb == 0:
            return 0.0
        return dot / (na * nb)

    # ----------------------------------------------------------------- marks

    def _limit_candidates(self, candidates: List[Dict[str, Any]], total_marks: float) -> List[Dict[str, Any]]:
        max_by_config = max(1, int(self.settings.rubric_max_criteria))
        step = self._mark_step(total_marks)
        max_by_marks = max(1, int(math.floor(total_marks / step + 1e-9)))
        limit = min(max_by_config, max_by_marks, RubricValidator.MAX_CRITERIA)
        if len(candidates) <= limit:
            return candidates if candidates else [self._fallback_candidate()]
        # keep highest-weight criteria first while preserving order
        indexed = sorted(enumerate(candidates), key=lambda p: (-p[1]["weight"], p[0]))[:limit]
        return [c for _, c in sorted(indexed, key=lambda p: p[0])]

    @staticmethod
    def _fallback_candidate() -> Dict[str, Any]:
        return RubricGenerator._cand(
            "Correctness and completeness of the response",
            "The response answers the question accurately and completely.",
            1.0,
            ["Question answered directly", "Content is accurate"],
        )

    @staticmethod
    def _mark_step(total_marks: float) -> float:
        for step in (1.0, 0.5, 0.25):
            if abs(total_marks / step - round(total_marks / step)) < 1e-9 and total_marks >= step:
                # prefer 0.5 granularity for flexibility when total is at least 2
                if step == 1.0 and total_marks >= 2:
                    return 0.5
                return step
        return 0.01

    @classmethod
    def _distribute_marks(cls, weights: List[float], total_marks: float) -> List[float]:
        """Largest-remainder allocation on a mark grid so that the sum equals total exactly."""
        if not weights:
            return []
        step = cls._mark_step(total_marks)
        units_total = int(round(total_marks / step))
        weight_sum = sum(weights) or 1.0
        raw = [units_total * w / weight_sum for w in weights]
        floors = [int(math.floor(r)) for r in raw]
        # guarantee at least one unit per criterion when possible
        for i in range(len(floors)):
            if floors[i] == 0 and sum(floors) < units_total:
                floors[i] = 1
        remaining = units_total - sum(floors)
        if remaining < 0:
            # remove units from the criteria with the smallest fractional remainder, never below 1
            order = sorted(range(len(raw)), key=lambda i: (raw[i] - floors[i]))
            for i in order:
                while remaining < 0 and floors[i] > 1:
                    floors[i] -= 1
                    remaining += 1
        order = sorted(range(len(raw)), key=lambda i: (-(raw[i] - floors[i]), i))
        idx = 0
        while remaining > 0:
            floors[order[idx % len(order)]] += 1
            remaining -= 1
            idx += 1
        marks = [round(f * step, 2) for f in floors]
        # final correction for floating drift
        drift = round(total_marks - sum(marks), 2)
        if abs(drift) >= 0.01:
            marks[0] = round(marks[0] + drift, 2)
        return marks

    # ------------------------------------------------------------- text bits

    @staticmethod
    def _scoring_guidance(criterion: str, max_marks: float) -> str:
        if max_marks <= 0:
            return "No marks allocated to this criterion."
        half = round(max_marks / 2, 2)
        return (
            f"Full marks ({max_marks:g}) for a complete and accurate response to '{criterion}'. "
            f"Partial credit (about {half:g}) for a partially correct or incomplete response. "
            "No marks if the criterion is not addressed or is incorrect."
        )

    @staticmethod
    def _build_title(question_text: str, request: GenerateRubricRequest) -> str:
        tasks = RubricGenerator.extract_tasks(question_text)
        topic = RubricGenerator._topic_phrase(question_text, tasks)
        topic = topic.rstrip("…").strip()
        if len(topic) > 60:
            topic = topic[:60].rsplit(" ", 1)[0]
        prefix = f"Q{request.question_id} " if request.question_id else ""
        return f"{prefix}Rubric: {topic[:1].upper()}{topic[1:]}"[:255]

    @staticmethod
    def _general_guidance(request: GenerateRubricRequest) -> str:
        parts = [
            "Award marks based on demonstrated understanding rather than keyword matching.",
            "Partial credit may be given for partially correct responses.",
        ]
        if request.cognitive_level:
            parts.append(f"Expected cognitive level: {request.cognitive_level.title()}.")
        if request.learning_outcome and (request.learning_outcome.code or request.learning_outcome.description):
            code = request.learning_outcome.code or "LO"
            desc = (request.learning_outcome.description or "").strip()
            parts.append(f"Related learning outcome {code}{': ' + desc if desc else ''}.")
        if request.expected_answer:
            parts.append("An expected answer was provided by faculty; use it as the primary reference.")
        parts.append("This is an AI-generated draft. Review and adjust before use.")
        return " ".join(parts)[:2000]
