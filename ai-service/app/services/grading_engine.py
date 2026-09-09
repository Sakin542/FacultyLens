"""STEP 27: AI Grading Assistance engine.

Produces *suggested* marks for a student answer by aligning the answer with the
approved rubric criterion by criterion. The engine never finalizes a grade.

How a suggestion is formed (transparent, rubric-traceable):

1. The answer is split into sentences.
2. For every rubric criterion, the criterion description and each expected
   indicator are compared with the answer sentences using MiniLM sentence
   embeddings (cosine similarity). When embeddings are unavailable, a
   lexical keyword-overlap fallback is used and reported in the metadata.
3. Indicator coverage (covered / partially covered / not found) and the
   description similarity are combined into a criterion coverage ratio.
4. The ratio is converted into suggested marks on the rubric's mark step and
   clamped to [0, criterion max]. Criterion marks are summed for the total.
5. The best-matching sentences are returned as evidence; unmatched indicators
   are returned as missing elements. Evaluation text is template-based and may
   optionally be refined by the configured seq2seq model (never trusted blindly).

No hidden chain-of-thought is produced or stored, and no confidence scores are
invented — only observable evidence and rubric coverage are reported.
"""

import logging
import math
import re
from typing import Any, Dict, List, Optional, Tuple

from app.config import get_settings
from app.schemas.grading import GradeAnswerRequest, GradingCriterionIn
from app.services.grading_validator import GradingValidator
from app.utils.text_utils import split_sentences

logger = logging.getLogger("facultylens.ai")

ENGINE_NAME = "facultylens-grading-engine"
ENGINE_VERSION = "1.0.0"

SEMANTIC_COVERED = 0.50
SEMANTIC_PARTIAL = 0.36
DESCRIPTION_LOW = 0.20
DESCRIPTION_HIGH = 0.60
LEXICAL_COVERED = 0.60
LEXICAL_PARTIAL = 0.34
INDICATOR_WEIGHT = 0.65
MAX_SENTENCES = 150
MAX_EVIDENCE = 3
EVIDENCE_CHARS = 220
MIN_ANSWER_WORDS = 5

LEVEL_STRONG = "STRONG"
LEVEL_PARTIAL = "PARTIAL"
LEVEL_LIMITED = "LIMITED"
LEVEL_NONE = "NOT_ADDRESSED"

STOP_WORDS = {
    "a", "an", "the", "and", "or", "of", "to", "in", "on", "for", "with", "is", "are", "was", "were", "be",
    "been", "it", "its", "this", "that", "these", "those", "as", "at", "by", "from", "into", "than", "then",
    "which", "who", "whom", "what", "when", "where", "why", "how", "can", "could", "should", "would", "will",
    "shall", "may", "might", "must", "do", "does", "did", "not", "no", "yes", "if", "so", "such", "any", "all",
    "each", "both", "some", "more", "most", "other", "own", "same", "very", "also", "about", "between", "through",
    "student", "answer", "correctly", "clearly", "explains", "explain", "describes", "describe", "identifies",
    "identify", "mentions", "mention", "states", "state", "provides", "provide", "gives", "give", "uses", "use",
    "using", "show", "shows", "including", "include", "includes", "e", "g", "i", "etc",
}


class GradingEngine:
    def __init__(self, hf_service: Any = None, text_generation_service: Any = None) -> None:
        self.hf_service = hf_service
        self.text_generation_service = text_generation_service
        self.settings = get_settings()

    # ------------------------------------------------------------------ public

    def grade(self, request: GradeAnswerRequest) -> Dict[str, Any]:
        answer_text = re.sub(r"\s+", " ", request.student_answer.text).strip()
        sentences = self._sentences(answer_text)
        answer_tokens = self._tokens(answer_text)
        very_short = len(answer_tokens) < MIN_ANSWER_WORDS

        criteria = sorted(request.rubric.criteria, key=lambda c: (c.sort_order or 0, c.id))
        targets: List[str] = []
        target_index: Dict[Tuple[int, str], int] = {}
        for criterion in criteria:
            desc = (criterion.description or criterion.criterion).strip()
            target_index[(criterion.id, "__description__")] = len(targets)
            targets.append(f"{criterion.criterion}. {desc}")
            for indicator in criterion.expected_indicators:
                target_index[(criterion.id, indicator)] = len(targets)
                targets.append(indicator)

        sentence_vecs, target_vecs, embedding_model = self._embed(sentences, targets)
        semantic = sentence_vecs is not None and target_vecs is not None

        generative_used = False
        criterion_results: List[Dict[str, Any]] = []
        total = 0.0

        for criterion in criteria:
            result, used_gen = self._grade_criterion(
                criterion=criterion,
                sentences=sentences,
                answer_tokens=answer_tokens,
                sentence_vecs=sentence_vecs,
                target_vecs=target_vecs,
                target_index=target_index,
                very_short=very_short,
            )
            generative_used = generative_used or used_gen
            criterion_results.append(result)
            total += result["suggested_marks"]

        suggested = round(total, 2)
        maximum = round(request.rubric.total_marks, 2)
        suggested = max(0.0, min(suggested, maximum))

        strengths = self._strengths(criterion_results)
        missing = self._missing(criterion_results)
        summary = self._summary(criterion_results, suggested, maximum, semantic, very_short)
        feedback = self._feedback(criterion_results, strengths, missing, suggested, maximum)

        method = "embedding_rubric_alignment" if semantic else "lexical_rubric_alignment"
        if generative_used:
            method = "ai_assisted"

        result = {
            "status": "success",
            "suggested_marks": suggested,
            "maximum_marks": maximum,
            "criterion_results": criterion_results,
            "overall_feedback": feedback,
            "strengths": strengths,
            "missing_elements": missing,
            "evaluation_summary": summary,
            "metadata": {
                "model": (
                    getattr(self.text_generation_service, "model_name", None)
                    if generative_used
                    else ENGINE_NAME
                ) or ENGINE_NAME,
                "version": ENGINE_VERSION,
                "embedding_model": embedding_model,
                "generative_model_used": generative_used,
                "generation_method": method,
                "criteria_count": len(criterion_results),
                "validation_passed": True,
            },
        }

        GradingValidator.assert_valid(
            result,
            [{"id": c.id, "max_marks": c.max_marks} for c in criteria],
            maximum,
        )
        return result

    # --------------------------------------------------------------- criterion

    def _grade_criterion(
        self,
        criterion: GradingCriterionIn,
        sentences: List[str],
        answer_tokens: set,
        sentence_vecs: Optional[List[List[float]]],
        target_vecs: Optional[List[List[float]]],
        target_index: Dict[Tuple[int, str], int],
        very_short: bool,
    ) -> Tuple[Dict[str, Any], bool]:
        semantic = sentence_vecs is not None and target_vecs is not None
        evidence_scores: Dict[int, float] = {}

        # Description coverage
        desc_vec = target_vecs[target_index[(criterion.id, "__description__")]] if semantic else None
        desc_sim, desc_best = self._best_match(desc_vec, sentence_vecs) if semantic else (None, None)
        if semantic and desc_sim is not None:
            desc_score = self._clamp((desc_sim - DESCRIPTION_LOW) / (DESCRIPTION_HIGH - DESCRIPTION_LOW))
            if desc_best is not None and desc_sim >= SEMANTIC_PARTIAL:
                evidence_scores[desc_best] = max(evidence_scores.get(desc_best, 0.0), desc_sim)
        else:
            desc_score = self._clamp(self._keyword_fraction(criterion.description or criterion.criterion, answer_tokens) / LEXICAL_COVERED)

        # Indicator coverage
        indicator_scores: List[float] = []
        missing: List[str] = []
        for indicator in criterion.expected_indicators:
            lex = self._keyword_fraction(indicator, answer_tokens)
            lex_score = 1.0 if lex >= LEXICAL_COVERED else (0.5 if lex >= LEXICAL_PARTIAL else 0.0)
            if lex_score > 0:
                best_lex = self._best_lexical_sentence(indicator, sentences)
                if best_lex is not None:
                    evidence_scores[best_lex] = max(evidence_scores.get(best_lex, 0.0), lex * 0.5)
            sem_score = 0.0
            if semantic:
                vec = target_vecs[target_index[(criterion.id, indicator)]]
                sim, best = self._best_match(vec, sentence_vecs)
                if sim is not None:
                    sem_score = 1.0 if sim >= SEMANTIC_COVERED else (0.5 if sim >= SEMANTIC_PARTIAL else 0.0)
                    if best is not None and sem_score > 0:
                        evidence_scores[best] = max(evidence_scores.get(best, 0.0), sim)
            score = max(lex_score, sem_score)
            indicator_scores.append(score)
            if score == 0.0:
                missing.append(indicator)
            elif score < 1.0:
                missing.append(f"{indicator} (only partially evident)")

        if indicator_scores:
            coverage = INDICATOR_WEIGHT * (sum(indicator_scores) / len(indicator_scores)) + (1 - INDICATOR_WEIGHT) * desc_score
        else:
            coverage = desc_score
        if very_short:
            coverage = min(coverage, 0.25)
        coverage = self._clamp(coverage)

        max_marks = round(float(criterion.max_marks), 2)
        marks = self._round_to_step(max_marks * coverage, self._mark_step(max_marks))
        marks = max(0.0, min(marks, max_marks))
        if max_marks == 0:
            marks = 0.0

        level = self._level(coverage, marks)
        evidence = self._evidence(evidence_scores, sentences)
        if level == LEVEL_NONE and not criterion.expected_indicators:
            missing.append(f"No clear discussion of: {criterion.criterion}")

        covered = sum(1 for s in indicator_scores if s >= 1.0)
        evaluation = self._evaluation_text(criterion, level, covered, len(indicator_scores), very_short)
        refined, used_gen = self._refine_evaluation(criterion, level, evidence, missing, evaluation)

        return (
            {
                "rubric_criterion_id": criterion.id,
                "criterion": criterion.criterion,
                "suggested_marks": round(marks, 2),
                "maximum_marks": max_marks,
                "evaluation": refined,
                "evidence": evidence,
                "missing_elements": missing[:8],
                "coverage_level": level,
            },
            used_gen,
        )

    # ------------------------------------------------------------------- text

    @staticmethod
    def _evaluation_text(criterion: GradingCriterionIn, level: str, covered: int, total: int, very_short: bool) -> str:
        name = criterion.criterion
        indicator_note = f" {covered} of {total} expected indicators are evident in the answer." if total else ""
        if very_short and level != LEVEL_STRONG:
            return f"The answer is very brief, so coverage of '{name}' could not be established beyond a minimal level.{indicator_note}"
        if level == LEVEL_STRONG:
            return f"The answer addresses '{name}' well.{indicator_note}"
        if level == LEVEL_PARTIAL:
            return f"The answer partially addresses '{name}'; some expected elements are present but the coverage is incomplete.{indicator_note}"
        if level == LEVEL_LIMITED:
            return f"The answer shows only limited coverage of '{name}'.{indicator_note}"
        return f"No clear evidence for '{name}' was found in the answer.{indicator_note}"

    def _refine_evaluation(
        self,
        criterion: GradingCriterionIn,
        level: str,
        evidence: List[str],
        missing: List[str],
        fallback: str,
    ) -> Tuple[str, bool]:
        service = self.text_generation_service
        if not service or not getattr(service, "is_configured", False):
            return fallback, False
        prompt = (
            "Write one sentence of constructive grading feedback for a student answer on a single rubric criterion. "
            "Do not invent facts. Use only the observed evidence and missing elements.\n"
            f"Criterion: {criterion.criterion}. {criterion.description}\n"
            f"Coverage level: {level.replace('_', ' ').title()}\n"
            f"Observed evidence: {'; '.join(evidence[:2]) if evidence else 'none'}\n"
            f"Missing elements: {'; '.join(missing[:3]) if missing else 'none'}\n"
            "Feedback:"
        )
        try:
            lines = service.generate_lines(prompt, max_new_tokens=64)
        except Exception as e:
            logger.warning(f"Grading feedback refinement failed: {e}")
            return fallback, False
        for line in lines or []:
            text = line.strip()
            if 20 <= len(text) <= 300 and re.search(r"[A-Za-z]", text):
                return f"{fallback} {text}", True
        return fallback, False

    @staticmethod
    def _strengths(results: List[Dict[str, Any]]) -> List[str]:
        strengths: List[str] = []
        for r in results:
            if r["coverage_level"] == LEVEL_STRONG:
                strengths.append(f"Addresses '{r['criterion']}' well.")
            elif r["coverage_level"] == LEVEL_PARTIAL and r["evidence"]:
                strengths.append(f"Shows relevant understanding for '{r['criterion']}'.")
        return strengths[:8]

    @staticmethod
    def _missing(results: List[Dict[str, Any]]) -> List[str]:
        missing: List[str] = []
        for r in results:
            for item in r["missing_elements"]:
                entry = f"{r['criterion']}: {item}"
                if entry not in missing:
                    missing.append(entry)
        return missing[:10]

    @staticmethod
    def _summary(results: List[Dict[str, Any]], suggested: float, maximum: float, semantic: bool, very_short: bool) -> str:
        counts = {LEVEL_STRONG: 0, LEVEL_PARTIAL: 0, LEVEL_LIMITED: 0, LEVEL_NONE: 0}
        for r in results:
            counts[r["coverage_level"]] = counts.get(r["coverage_level"], 0) + 1
        parts = [
            f"Suggested {GradingEngine._fmt(suggested)} of {GradingEngine._fmt(maximum)} marks across {len(results)} rubric criteria: "
            f"{counts[LEVEL_STRONG]} strong, {counts[LEVEL_PARTIAL]} partial, {counts[LEVEL_LIMITED]} limited, {counts[LEVEL_NONE]} not addressed."
        ]
        if very_short:
            parts.append("The answer is very short, which limits how much rubric coverage can be established.")
        parts.append(
            "Coverage was judged by semantic alignment between the answer and each rubric criterion."
            if semantic
            else "Coverage was judged by keyword alignment between the answer and each rubric criterion (embedding model unavailable)."
        )
        return " ".join(parts)

    @staticmethod
    def _feedback(results: List[Dict[str, Any]], strengths: List[str], missing: List[str], suggested: float, maximum: float) -> str:
        ratio = suggested / maximum if maximum else 0.0
        if ratio >= 0.8:
            opening = "The answer demonstrates a strong understanding of the rubric criteria."
        elif ratio >= 0.5:
            opening = "The answer demonstrates a reasonable understanding but misses some important details."
        elif ratio > 0.15:
            opening = "The answer shows a basic understanding but leaves several rubric criteria insufficiently addressed."
        else:
            opening = "The answer provides little evidence for the rubric criteria."
        sentences = [opening]
        if strengths:
            sentences.append("Strengths: " + " ".join(strengths[:3]))
        if missing:
            sentences.append("To improve, address: " + "; ".join(m for m in missing[:4]) + ".")
        return " ".join(sentences)

    # ---------------------------------------------------------------- helpers

    def _embed(self, sentences: List[str], targets: List[str]) -> Tuple[Optional[List[List[float]]], Optional[List[List[float]]], Optional[str]]:
        if not self.hf_service or not sentences or not targets:
            return None, None, None
        try:
            vectors = self.hf_service.generate_batch_embeddings(sentences + targets)
            if not vectors or len(vectors) != len(sentences) + len(targets):
                return None, None, None
            return vectors[: len(sentences)], vectors[len(sentences):], getattr(self.hf_service, "model_name", None)
        except Exception as e:
            logger.warning(f"Grading engine embeddings unavailable, using lexical fallback: {e}")
            return None, None, None

    @staticmethod
    def _sentences(text: str) -> List[str]:
        raw = split_sentences(text) if text else []
        parts: List[str] = []
        for s in raw:
            for piece in re.split(r"\s*[\n;]\s*", s):
                piece = piece.strip()
                if len(piece) >= 3:
                    parts.append(piece)
        return parts[:MAX_SENTENCES] if parts else ([text] if text else [])

    @staticmethod
    def _tokens(text: str) -> set:
        return {t for t in re.findall(r"[a-z0-9]+", (text or "").lower()) if t not in STOP_WORDS and len(t) > 1}

    @classmethod
    def _keyword_fraction(cls, phrase: str, answer_tokens: set) -> float:
        keys = cls._tokens(phrase)
        if not keys:
            return 0.0
        found = sum(1 for k in keys if k in answer_tokens or any(cls._stem(k) == cls._stem(t) for t in answer_tokens))
        return found / len(keys)

    @classmethod
    def _best_lexical_sentence(cls, phrase: str, sentences: List[str]) -> Optional[int]:
        best_idx, best_frac = None, 0.0
        for idx, sentence in enumerate(sentences):
            frac = cls._keyword_fraction(phrase, cls._tokens(sentence))
            if frac > best_frac:
                best_idx, best_frac = idx, frac
        return best_idx if best_frac >= LEXICAL_PARTIAL else None

    @staticmethod
    def _stem(token: str) -> str:
        for suffix in ("ization", "isation", "ations", "ation", "ingly", "ings", "ing", "ies", "ers", "es", "ed", "ly", "s"):
            if len(token) > len(suffix) + 3 and token.endswith(suffix):
                token = token[: -len(suffix)]
                break
        # "reduce"/"reduces"/"reduced" and "organize"/"organizing" collapse to one stem
        return token[:-1] if len(token) > 4 and token.endswith("e") else token

    @staticmethod
    def _best_match(vec: Optional[List[float]], sentence_vecs: Optional[List[List[float]]]) -> Tuple[Optional[float], Optional[int]]:
        if vec is None or not sentence_vecs:
            return None, None
        best_sim, best_idx = -1.0, None
        for idx, s_vec in enumerate(sentence_vecs):
            sim = GradingEngine._cosine(vec, s_vec)
            if sim > best_sim:
                best_sim, best_idx = sim, idx
        return (best_sim if best_idx is not None else None), best_idx

    @staticmethod
    def _cosine(a: List[float], b: List[float]) -> float:
        dot = sum(x * y for x, y in zip(a, b))
        na = math.sqrt(sum(x * x for x in a))
        nb = math.sqrt(sum(y * y for y in b))
        if na == 0 or nb == 0:
            return 0.0
        return dot / (na * nb)

    @staticmethod
    def _evidence(scores: Dict[int, float], sentences: List[str]) -> List[str]:
        ranked = sorted(scores.items(), key=lambda kv: kv[1], reverse=True)[:MAX_EVIDENCE]
        evidence: List[str] = []
        for idx, _ in sorted(ranked, key=lambda kv: kv[0]):
            text = sentences[idx].strip()
            if len(text) > EVIDENCE_CHARS:
                text = text[: EVIDENCE_CHARS - 1].rstrip() + "…"
            if text and text not in evidence:
                evidence.append(text)
        return evidence

    @staticmethod
    def _mark_step(max_marks: float) -> float:
        if max_marks >= 2:
            return 0.5
        if max_marks >= 0.5:
            return 0.25
        return 0.01

    @staticmethod
    def _round_to_step(value: float, step: float) -> float:
        if step <= 0:
            return round(value, 2)
        return round(round(value / step) * step, 2)

    @staticmethod
    def _level(coverage: float, marks: float) -> str:
        if marks <= 0:
            return LEVEL_NONE
        if coverage >= 0.8:
            return LEVEL_STRONG
        if coverage >= 0.5:
            return LEVEL_PARTIAL
        return LEVEL_LIMITED

    @staticmethod
    def _clamp(value: float, low: float = 0.0, high: float = 1.0) -> float:
        return max(low, min(high, value))

    @staticmethod
    def _fmt(value: float) -> str:
        return str(int(value)) if float(value).is_integer() else f"{value:g}"
