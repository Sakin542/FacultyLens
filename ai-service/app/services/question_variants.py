"""Question variant generator (exam sets A/B/C).

Produces alternative versions of one exam question so separate sets reduce copying:

* numbers  - every numeric value is changed consistently (same original -> same new value), keeping integers
             integral, decimals at the same precision and percentages within 0-100. Numbers that look like labels
             (CO1, Q2, years, "(a)") are never touched.
* wording  - a paraphrase from the configured generation model when available, accepted only if it keeps every
             number and stays semantically close (MiniLM cosine); otherwise deterministic command-verb and phrase
             rewording.
* both     - wording first, then numbers.

The expected answer gets the same number mapping. If it contains numbers that are NOT echoed from the question
(derived results), the variant is flagged `answer_needs_review`: faculty must recompute the key.
"""

from __future__ import annotations

import math
import random
import re
from typing import Any, Dict, List, Optional, Tuple

NUMBER_RE = re.compile(r"(?<![A-Za-z_\d.])(\d+(?:\.\d+)?)(\s*%)?(?![A-Za-z_\d])")
LABEL_PREFIX_RE = re.compile(r"(?:\b(?:CO|PO|CLO|PLO|Q|Question|Section|Part|Chapter|Figure|Fig\.?|Table|Step|Unit|Lab)\s*-?\s*)$", re.IGNORECASE)

# Leading command verbs -> alternatives with the same Bloom intent
COMMAND_SYNONYMS: Dict[str, List[str]] = {
    "explain": ["Describe", "Discuss"],
    "describe": ["Explain", "Outline"],
    "discuss": ["Explain", "Examine"],
    "calculate": ["Compute", "Determine"],
    "compute": ["Calculate", "Determine"],
    "find": ["Determine", "Calculate"],
    "determine": ["Find", "Compute"],
    "define": ["State the meaning of", "Give the definition of"],
    "list": ["Name", "Enumerate"],
    "name": ["List", "Identify"],
    "identify": ["Point out", "Name"],
    "compare": ["Contrast", "Distinguish between"],
    "differentiate": ["Distinguish", "Compare"],
    "distinguish": ["Differentiate", "Compare"],
    "write": ["Give", "Provide"],
    "design": ["Construct", "Develop"],
    "construct": ["Design", "Build"],
    "analyze": ["Examine", "Analyse"],
    "analyse": ["Examine", "Analyze"],
    "evaluate": ["Assess", "Critically evaluate"],
    "justify": ["Give reasons for", "Defend"],
    "prove": ["Show", "Demonstrate"],
    "show": ["Prove", "Demonstrate"],
    "illustrate": ["Demonstrate", "Show with an example"],
    "derive": ["Obtain", "Work out"],
    "draw": ["Sketch", "Construct"],
    "state": ["Write down", "Give"],
    "solve": ["Work out", "Find the solution of"],
}

PHRASE_SWAPS: List[Tuple[str, str]] = [
    ("the following", "the given"),
    ("Consider ", "Suppose "),
    ("Suppose ", "Consider "),
    ("with an example", "with a suitable example"),
    ("with a suitable example", "with an example"),
    ("in detail", "thoroughly"),
    ("briefly", "concisely"),
    ("What is meant by", "What do you understand by"),
    ("What do you understand by", "What is meant by"),
    ("What is", "Define"),
]


def _is_label(text: str, start: int, value: str) -> bool:
    """Numbers that identify things rather than quantify them."""
    before = text[max(0, start - 12):start]
    if LABEL_PREFIX_RE.search(before):
        return True
    if start > 0 and text[start - 1] == "(" and start + len(value) < len(text) and text[start + len(value)] == ")" and len(value) <= 2:
        return True  # "(1)" list markers
    if "." not in value and len(value) == 4 and 1900 <= int(value) <= 2100:
        return True  # years
    return False


def _new_value(value: str, is_percent: bool, rng: random.Random) -> str:
    decimals = len(value.split(".")[1]) if "." in value else 0
    original = float(value)
    for _ in range(20):
        factor = rng.choice([rng.uniform(0.55, 0.9), rng.uniform(1.15, 1.75)])
        candidate = original * factor if original != 0 else rng.randint(1, 9)
        if is_percent:
            candidate = min(candidate, 95 if original <= 95 else 100)
        if decimals == 0:
            candidate = max(1, int(round(candidate)))
            if candidate != int(original):
                return str(candidate)
        else:
            rounded = round(candidate, decimals)
            if rounded > 0 and not math.isclose(rounded, original):
                return f"{rounded:.{decimals}f}"
    return value


def vary_numbers(question: str, answer: Optional[str], rng: random.Random) -> Dict[str, Any]:
    mapping: Dict[str, str] = {}

    def replace(text: str, create: bool) -> str:
        out, last = [], 0
        for m in NUMBER_RE.finditer(text):
            value, pct = m.group(1), bool(m.group(2))
            if _is_label(text, m.start(1), value):
                continue
            if value not in mapping:
                if not create:
                    continue
                mapping[value] = _new_value(value, pct, rng)
            out.append(text[last:m.start(1)])
            out.append(mapping[value])
            last = m.end(1)
        out.append(text[last:])
        return "".join(out)

    new_question = replace(question, create=True)
    new_answer = replace(answer, create=False) if answer else answer

    derived = []
    if answer:
        for m in NUMBER_RE.finditer(answer):
            if not _is_label(answer, m.start(1), m.group(1)) and m.group(1) not in mapping:
                derived.append(m.group(1))

    # Worked arithmetic in the key ("200 x 512 = 102400") is recomputed with the new values
    recomputed: List[str] = []
    if new_answer and mapping:
        new_answer, recomputed = recompute_arithmetic(new_answer)
    # A derived value is settled only if it was the result of a recomputed expression; anything else needs a human
    original_results = [m.group(2) for m in ARITH_RE.finditer(answer or "")] if recomputed else []
    unresolved = [d for d in derived if d not in original_results]

    changes = [{"from": k, "to": v} for k, v in mapping.items() if k != v]
    return {
        "question_text": new_question,
        "expected_answer": new_answer,
        "number_changes": changes,
        "answer_needs_review": bool(answer) and (bool(unresolved) or (bool(changes) and new_answer == answer)),
        "derived_numbers": unresolved,
        "recomputed": recomputed,
    }


def reword(question: str, rng: random.Random) -> Tuple[str, bool]:
    text = question.strip()
    changed = False

    m = re.match(r"^(\s*(?:\(?[a-z0-9]\)|\d+[.)])?\s*)([A-Za-z]+)\b", text)
    if m and m.group(2).lower() in COMMAND_SYNONYMS:
        replacement = rng.choice(COMMAND_SYNONYMS[m.group(2).lower()])
        text = text[: m.start(2)] + replacement + text[m.end(2):]
        changed = True

    swaps = PHRASE_SWAPS[:]
    rng.shuffle(swaps)
    for src, dst in swaps:
        if src in text and dst not in text:
            text = text.replace(src, dst, 1)
            changed = True
            break

    return text, changed


ARITH_RE = re.compile(r"((?:\(?\s*\d+(?:\.\d+)?\s*\)?\s*[-+*/x×÷^]\s*)+\(?\s*\d+(?:\.\d+)?\s*\)?)\s*=\s*(\d+(?:\.\d+)?)")


def _safe_eval(expr: str) -> Optional[float]:
    """Evaluate + - * / ^ and parentheses over numbers only (no names, calls or attributes)."""
    import ast
    import operator

    ops = {ast.Add: operator.add, ast.Sub: operator.sub, ast.Mult: operator.mul, ast.Div: operator.truediv, ast.Pow: operator.pow}
    # Leading whitespace would be parsed as an indentation error
    cleaned = expr.strip().replace("×", "*").replace("÷", "/").replace("^", "**")
    cleaned = re.sub(r"(?<=[\d)\s])x(?=[\s\d(])", "*", cleaned)

    def ev(node: ast.AST) -> float:
        if isinstance(node, ast.Expression):
            return ev(node.body)
        if isinstance(node, ast.Constant) and isinstance(node.value, (int, float)):
            return float(node.value)
        if isinstance(node, ast.BinOp) and type(node.op) in ops:
            right = ev(node.right)
            if isinstance(node.op, ast.Pow) and abs(right) > 6:
                raise ValueError("exponent too large")
            return ops[type(node.op)](ev(node.left), right)
        if isinstance(node, ast.UnaryOp) and isinstance(node.op, ast.USub):
            return -ev(node.operand)
        raise ValueError("unsupported expression")

    try:
        return ev(ast.parse(cleaned, mode="eval"))
    except Exception:
        return None


def recompute_arithmetic(answer: str) -> Tuple[str, List[str]]:
    """Rewrite "expr = result" so the result matches the (already re-numbered) expression. Returns the new text and
    the results it recomputed."""
    recomputed: List[str] = []

    def repl(m: "re.Match[str]") -> str:
        value = _safe_eval(m.group(1))
        if value is None:
            return m.group(0)
        old = m.group(2)
        decimals = len(old.split(".")[1]) if "." in old else (0 if float(value).is_integer() else 2)
        new = f"{value:.{decimals}f}"
        recomputed.append(new)
        return f"{m.group(1).rstrip()} = {new}"

    return ARITH_RE.sub(repl, answer), recomputed


def _numbers(text: str) -> List[str]:
    return sorted(m.group(1) for m in NUMBER_RE.finditer(text or ""))


def _cosine(a: List[float], b: List[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a)) or 1.0
    nb = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (na * nb)


class QuestionVariantGenerator:
    MIN_PARAPHRASE_SIMILARITY = 0.75
    PROMPTS = [
        "Paraphrase this exam question. Keep the same meaning, difficulty and every number exactly: {q}",
        "Rewrite this university exam question in different words without changing any number or what is asked: {q}",
        "Reword the following question for a second exam set, preserving all values and the task: {q}",
    ]

    def __init__(self, generation_service: Any = None, hf_service: Any = None) -> None:
        self.generation_service = generation_service
        self.hf_service = hf_service

    def _embed(self, texts: List[str]) -> Optional[List[List[float]]]:
        try:
            if self.hf_service is not None and self.hf_service.is_loaded:
                return self.hf_service.generate_batch_embeddings(texts)
        except Exception:  # pragma: no cover - model issues degrade to no similarity score
            return None
        return None

    def _model_paraphrase(self, question: str, index: int) -> Optional[str]:
        gen = self.generation_service
        if gen is None or not getattr(gen, "is_configured", False):
            return None
        try:
            text = gen.generate(self.PROMPTS[index % len(self.PROMPTS)].format(q=question), max_new_tokens=160)
        except Exception:  # pragma: no cover
            return None
        text = (text or "").strip()
        if not text or text.lower() == question.strip().lower() or _numbers(text) != _numbers(question):
            return None
        return text

    def generate(self, question: str, expected_answer: Optional[str], count: int, mode: str, seed: Optional[int]) -> Dict[str, Any]:
        question = (question or "").strip()
        count = max(1, min(int(count or 1), 5))
        mode = mode if mode in ("numbers", "wording", "both") else "both"
        base_seed = seed if seed is not None else 1729
        variants: List[Dict[str, Any]] = []
        seen = {question.lower()}
        used_model = False

        for i in range(count * 3):
            if len(variants) >= count:
                break
            rng = random.Random(base_seed * 7919 + i)
            text, answer, warnings, methods = question, expected_answer, [], []
            number_info: Dict[str, Any] = {"number_changes": [], "answer_needs_review": False, "derived_numbers": []}

            if mode in ("wording", "both"):
                paraphrase = self._model_paraphrase(question, i)
                if paraphrase:
                    emb = self._embed([question, paraphrase])
                    if emb is None or _cosine(emb[0], emb[1]) >= self.MIN_PARAPHRASE_SIMILARITY:
                        text, used_model = paraphrase, True
                        methods.append("model_paraphrase")
                if "model_paraphrase" not in methods:
                    text, changed = reword(question, rng)
                    if changed:
                        methods.append("rule_based_rewording")
                    elif mode == "wording":
                        warnings.append("No safe rewording was found; the wording is unchanged.")

            if mode in ("numbers", "both"):
                number_info = vary_numbers(text, expected_answer, rng)
                text, answer = number_info["question_text"], number_info["expected_answer"]
                if number_info["number_changes"]:
                    methods.append("numeric")
                elif mode == "numbers":
                    warnings.append("The question has no numeric values to change.")

            if text.lower() in seen:
                continue
            seen.add(text.lower())

            if number_info["answer_needs_review"]:
                warnings.append("The expected answer contains values derived from the changed numbers; recompute the answer key.")

            variants.append({
                "question_text": text,
                "expected_answer": answer,
                "methods": methods,
                "number_changes": number_info["number_changes"],
                "answer_needs_review": number_info["answer_needs_review"],
                "warnings": warnings,
                "similarity": None,
            })

        if variants:
            emb = self._embed([question] + [v["question_text"] for v in variants])
            if emb:
                for v, vec in zip(variants, emb[1:]):
                    v["similarity"] = round(_cosine(emb[0], vec), 3)

        return {
            "original": question,
            "mode": mode,
            "variants": variants,
            "engine": "model_paraphrase" if used_model else "deterministic",
            "model": getattr(self.generation_service, "model_name", None) if used_model else None,
            "message": None if variants else "No distinct variant could be produced for this question.",
        }
