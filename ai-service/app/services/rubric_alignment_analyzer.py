"""STEP 28: Answer <-> Rubric Alignment analyzer.

Answers "how well does this answer satisfy each rubric criterion?" — not "what marks
does it deserve" (STEP 27) and not "is it correct". Alignment is evidence of coverage;
correctness must be verified by faculty.

Hybrid, deterministic pipeline:

    answer text -> sentences -> MiniLM embeddings (batched with criterion targets)
        -> per-criterion signal = 0.65 * mean(indicator signals) + 0.35 * description signal
           (each signal = max(semantic cosine over sentences, lexical coverage * 0.8);
            when embeddings are unavailable only the lexical part is used and reported)
        -> thresholds (configurable) -> STRONG / PARTIAL / WEAK / NOT_ALIGNED
        -> weight 1 / 0.5 / 0.25 / 0
        -> mark-weighted overall score (primary) + unweighted score

Evidence is always an excerpt of the actual answer (never generated). Explanations are
template-based and may optionally be refined by the configured seq2seq model; the final
status/score never depends on free-form model output. No chain-of-thought is produced.
"""

import logging
import re
from typing import Any, Dict, List, Optional, Tuple

from app.config import get_settings
from app.schemas.grading import GradingCriterionIn
from app.schemas.rubric_alignment import ALIGNMENT_WEIGHTS, AnalyzeAnswerRubricAlignmentRequest
from app.services.grading_engine import GradingEngine
from app.services.rubric_alignment_validator import RubricAlignmentValidator

logger = logging.getLogger("facultylens.ai")

ENGINE_NAME = "facultylens-rubric-alignment-engine"
ENGINE_VERSION = "1.0.0"

INDICATOR_WEIGHT = 0.65
LEXICAL_SCALE = 0.80
MAX_EVIDENCE = 3
EVIDENCE_CHARS = 220

STRONG, PARTIAL, WEAK, NOT_ALIGNED = "STRONG", "PARTIAL", "WEAK", "NOT_ALIGNED"

# Overall status from the mark-weighted percentage.
OVERALL_STRONG = 75.0
OVERALL_PARTIAL = 45.0
OVERALL_WEAK = 20.0


class RubricAlignmentAnalyzer:
    def __init__(self, hf_service: Any = None, text_generation_service: Any = None) -> None:
        self.hf_service = hf_service
        self.text_generation_service = text_generation_service
        settings = get_settings()
        self.strong = float(settings.rubric_alignment_strong_threshold)
        self.partial = float(settings.rubric_alignment_partial_threshold)
        self.weak = float(settings.rubric_alignment_weak_threshold)
        if not (self.strong > self.partial > self.weak > 0):
            raise ValueError("Rubric alignment thresholds must satisfy strong > partial > weak > 0.")

    # ------------------------------------------------------------------ public

    def analyze(self, request: AnalyzeAnswerRubricAlignmentRequest) -> Dict[str, Any]:
        answer_text = re.sub(r"\s+", " ", request.student_answer.text).strip()
        sentences = GradingEngine._sentences(answer_text)
        answer_tokens = GradingEngine._tokens(answer_text)

        criteria = sorted(request.rubric.criteria, key=lambda c: (c.sort_order or 0, c.id))
        targets: List[str] = []
        index: Dict[Tuple[int, str], int] = {}
        for c in criteria:
            index[(c.id, "__description__")] = len(targets)
            targets.append((c.description or c.criterion).strip())
            index[(c.id, "__title__")] = len(targets)
            targets.append(c.criterion.strip())
            for ind in c.expected_indicators:
                index[(c.id, ind)] = len(targets)
                targets.append(ind)

        embedder = GradingEngine(hf_service=self.hf_service)
        sentence_vecs, target_vecs, embedding_model = embedder._embed(sentences, targets)
        semantic = sentence_vecs is not None and target_vecs is not None

        generative_used = False
        results: List[Dict[str, Any]] = []
        for c in criteria:
            item, used = self._align_criterion(c, sentences, answer_tokens, sentence_vecs, target_vecs, index, semantic)
            generative_used = generative_used or used
            results.append(item)

        total_marks = sum(r["max_marks"] for r in results)
        weighted = round(sum(r["alignment_score"] * r["max_marks"] for r in results) / total_marks * 100, 2) if total_marks else 0.0
        unweighted = round(sum(r["alignment_score"] for r in results) / len(results) * 100, 2)
        counts = {
            "strong": sum(1 for r in results if r["alignment_status"] == STRONG),
            "partial": sum(1 for r in results if r["alignment_status"] == PARTIAL),
            "weak": sum(1 for r in results if r["alignment_status"] == WEAK),
            "not_aligned": sum(1 for r in results if r["alignment_status"] == NOT_ALIGNED),
        }
        overall_status = self._overall_status(weighted)

        result = {
            "status": "success",
            "overall_alignment_score": weighted,
            "unweighted_alignment_score": unweighted,
            "overall_alignment_status": overall_status,
            "counts": counts,
            "summary": self._summary(overall_status, weighted, counts, len(results), semantic),
            "strengths": [f"Addresses '{r['criterion']}' with clear evidence." for r in results if r["alignment_status"] == STRONG][:8],
            "missing_elements": self._missing(results),
            "criterion_alignments": results,
            "metadata": {
                "model": (getattr(self.text_generation_service, "model_name", None) if generative_used else ENGINE_NAME) or ENGINE_NAME,
                "version": ENGINE_VERSION,
                "embedding_model": embedding_model,
                "generative_model_used": generative_used,
                "method": "semantic_and_rubric_alignment" if semantic else "lexical_rubric_alignment",
                "thresholds": {"strong": self.strong, "partial": self.partial, "weak": self.weak},
                "criteria_count": len(results),
                "validation_passed": True,
            },
        }

        RubricAlignmentValidator.assert_valid(result, [{"id": c.id, "max_marks": c.max_marks} for c in criteria])
        return result

    # --------------------------------------------------------------- criterion

    def _align_criterion(
        self,
        criterion: GradingCriterionIn,
        sentences: List[str],
        answer_tokens: set,
        sentence_vecs: Optional[List[List[float]]],
        target_vecs: Optional[List[List[float]]],
        index: Dict[Tuple[int, str], int],
        semantic: bool,
    ) -> Tuple[Dict[str, Any], bool]:
        evidence_scores: Dict[int, float] = {}

        def signal(key: str, phrase: str) -> float:
            sem = 0.0
            if semantic:
                sim, best = GradingEngine._best_match(target_vecs[index[(criterion.id, key)]], sentence_vecs)
                if sim is not None:
                    sem = max(0.0, sim)
                    if best is not None and sem >= self.weak:
                        evidence_scores[best] = max(evidence_scores.get(best, 0.0), sem)
            lex = GradingEngine._keyword_fraction(phrase, answer_tokens) * LEXICAL_SCALE
            if lex >= self.weak:
                best_lex = GradingEngine._best_lexical_sentence(phrase, sentences)
                if best_lex is not None:
                    evidence_scores[best_lex] = max(evidence_scores.get(best_lex, 0.0), lex)
            return min(1.0, max(sem, lex))

        # Long template descriptions dilute similarity; the short criterion title is a second, equally valid target.
        desc_signal = max(
            signal("__description__", criterion.description or criterion.criterion),
            signal("__title__", criterion.criterion),
        )

        indicator_signals: List[float] = []
        missing: List[str] = []
        for ind in criterion.expected_indicators:
            s = signal(ind, ind)
            indicator_signals.append(s)
            if s < self.weak:
                missing.append(f"No evidence found for: {ind}.")
            elif s < self.partial:
                missing.append(f"Only limited evidence found for: {ind}.")

        combined = (INDICATOR_WEIGHT * (sum(indicator_signals) / len(indicator_signals)) + (1 - INDICATOR_WEIGHT) * desc_signal) if indicator_signals else desc_signal
        combined = round(min(1.0, max(0.0, combined)), 4)
        status = self._classify(combined)
        evidence = GradingEngine._evidence(evidence_scores, sentences)

        if status == NOT_ALIGNED and not criterion.expected_indicators:
            missing.append(f"No evidence addressing '{criterion.criterion}' was identified.")

        explanation = self._explanation(criterion, status, evidence, missing)
        explanation, used_gen = self._refine(criterion, status, evidence, missing, explanation)

        return (
            {
                "rubric_criterion_id": criterion.id,
                "criterion": criterion.criterion,
                "max_marks": round(float(criterion.max_marks), 2),
                "alignment_status": status,
                "alignment_score": ALIGNMENT_WEIGHTS[status],
                "similarity": combined,
                "evidence": evidence[:MAX_EVIDENCE],
                "missing_elements": missing[:8],
                "explanation": explanation,
            },
            used_gen,
        )

    def _classify(self, value: float) -> str:
        if value >= self.strong:
            return STRONG
        if value >= self.partial:
            return PARTIAL
        if value >= self.weak:
            return WEAK
        return NOT_ALIGNED

    @staticmethod
    def _overall_status(weighted: float) -> str:
        if weighted >= OVERALL_STRONG:
            return STRONG
        if weighted >= OVERALL_PARTIAL:
            return PARTIAL
        if weighted >= OVERALL_WEAK:
            return WEAK
        return NOT_ALIGNED

    # ------------------------------------------------------------------- text

    @staticmethod
    def _explanation(criterion: GradingCriterionIn, status: str, evidence: List[str], missing: List[str]) -> str:
        name = criterion.criterion
        caution = " Faculty review is recommended to verify correctness."
        if status == STRONG:
            return f"The answer directly addresses '{name}' and provides relevant evidence.{caution}"
        if status == PARTIAL:
            return f"The answer addresses '{name}' but misses some expected elements.{caution}"
        if status == WEAK:
            return f"Only limited or unclear evidence related to '{name}' was found in the answer.{caution}"
        return f"No meaningful evidence addressing '{name}' was identified in the answer."

    def _refine(self, criterion: GradingCriterionIn, status: str, evidence: List[str], missing: List[str], fallback: str) -> Tuple[str, bool]:
        service = self.text_generation_service
        if not service or not getattr(service, "is_configured", False):
            return fallback, False
        prompt = (
            "Write one neutral sentence describing how a student answer relates to a rubric criterion. "
            "Use only the observed evidence and missing elements. Do not judge correctness.\n"
            f"Criterion: {criterion.criterion}. {criterion.description}\n"
            f"Alignment: {status.replace('_', ' ').title()}\n"
            f"Evidence: {'; '.join(evidence[:2]) if evidence else 'none'}\n"
            f"Missing: {'; '.join(missing[:3]) if missing else 'none'}\n"
            "Sentence:"
        )
        try:
            lines = service.generate_lines(prompt, max_new_tokens=64)
        except Exception as e:
            logger.warning(f"Alignment explanation refinement failed: {e}")
            return fallback, False
        for line in lines or []:
            text = line.strip()
            if 20 <= len(text) <= 300 and re.search(r"[A-Za-z]", text):
                return f"{fallback} {text}", True
        return fallback, False

    @staticmethod
    def _missing(results: List[Dict[str, Any]]) -> List[str]:
        out: List[str] = []
        for r in results:
            for m in r["missing_elements"]:
                entry = f"{r['criterion']}: {m}"
                if entry not in out:
                    out.append(entry)
        return out[:12]

    @staticmethod
    def _summary(status: str, weighted: float, counts: Dict[str, int], n: int, semantic: bool) -> str:
        if status == STRONG:
            opening = "The answer addresses most rubric criteria with clear evidence."
        elif status == PARTIAL:
            opening = "The answer addresses several rubric criteria but leaves important requirements insufficiently addressed."
        elif status == WEAK:
            opening = "The answer shows limited alignment with the rubric criteria."
        else:
            opening = "The answer provides little evidence for the rubric criteria."
        method = (
            "Alignment reflects semantic and keyword evidence of coverage, not correctness."
            if semantic
            else "Alignment reflects keyword evidence of coverage only (embedding model unavailable), not correctness."
        )
        return (
            f"{opening} Mark-weighted alignment {GradingEngine._fmt(weighted)}% across {n} criteria: "
            f"{counts['strong']} strong, {counts['partial']} partial, {counts['weak']} weak, {counts['not_aligned']} not aligned. {method}"
        )
