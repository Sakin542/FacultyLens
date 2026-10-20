"""Feedback drafter: short, rubric-based feedback for one student answer, written to the student. It never gives marks.

It reuses the grading engine's criterion analysis (MiniLM evidence matching per rubric criterion and indicator) and
throws the marks away. The feedback is built from what that analysis actually found:

- strengths come only from criteria with matched evidence in the answer (quoted back to the student);
- improvements come only from rubric indicators that were not found or only partly found;
- one next step targets the first missing point.

MiniLM's matches are re-checked word by word first, so the draft never praises a point the student did not make.

With the hosted provider, the generation model then rephrases the draft into a natural paragraph. Its version is used
only if it keeps every missing point, mentions no marks or scores, adds no numbers that are not in the answer or the
rubric and does not copy the worked example; otherwise the template draft is returned. Either way the result is a draft
that the teacher edits before it reaches a student.
"""

import re
from typing import Any, Dict, List, Optional

from app.schemas.grading import GradeAnswerRequest
from app.services import provider_context
from app.services.grading_engine import LEVEL_LIMITED, LEVEL_NONE, LEVEL_PARTIAL, LEVEL_STRONG, GradingEngine
from app.services.writing_tools import STOP, WritingToolError, _Base

TONES = ("encouraging", "neutral", "concise")
MAX_CRITERIA = 12
MAX_ANSWER_CHARS = 20000

OPENINGS = {
    "encouraging": {
        "strong": "Well done: your answer covers the main points this question asks for.",
        "mixed": "Good start: you have the core idea, and a few additions would make this answer complete.",
        "weak": "Thank you for your answer. It is a start, but several key points are still missing.",
    },
    "neutral": {
        "strong": "Your answer addresses the main points of the question.",
        "mixed": "Your answer covers part of what the question asks.",
        "weak": "Your answer does not yet address most of what the question asks.",
    },
    "concise": {
        "strong": "Covers the main points.",
        "mixed": "Partly complete.",
        "weak": "Most key points are missing.",
    },
}

COVERAGE_LABELS = {
    LEVEL_STRONG: "Addressed well",
    LEVEL_PARTIAL: "Partly addressed",
    LEVEL_LIMITED: "Barely addressed",
    LEVEL_NONE: "Not addressed",
}

# Rubric indicators are written for markers ("Mentions that BFS uses a queue"); strip the marker verb for students.
MARKER_VERBS = re.compile(
    r"^(?:(?:the\s+)?(?:student|answer)\s+)?(?:(?:correctly|clearly|accurately|briefly)\s+)?"
    r"(?:mentions?|explains?|describes?|states?|identifies?|gives?|provides?|uses?|includes?|defines?|lists?|shows?|discusses?|notes?)\s+(?:that\s+)?",
    re.I,
)
PARTIAL_SUFFIX = " (only partially evident)"
NO_DISCUSSION = "No clear discussion of: "
GRADE_WORDS = re.compile(r"\b(marks?|scored?|scores|grades?|graded|percent|out of)\b|%|\b\d+(?:\.\d+)?\s*(?:/\s*\d+|points?\b)", re.I)

# Worked example for the rewrite (deliberately on a different subject, so its content cannot leak into feedback)
EXAMPLE_NOTES = (
    "Tone: encouraging\n"
    "Opening: Good start: you have the core idea, and a few additions would make this answer complete.\n"
    "Done well: role of chlorophyll (\"chlorophyll absorbs light energy\")\n"
    "Missing: the products of photosynthesis; where the reaction takes place\n"
    "Next step: add a sentence on the products of photosynthesis."
)
EXAMPLE_FEEDBACK = (
    "Good start: you clearly explained that chlorophyll absorbs light energy. To make the answer complete, also state "
    "the products of photosynthesis and say where the reaction takes place. Next step: add a sentence on the products "
    "of photosynthesis."
)
EXAMPLE_ONLY_WORDS = {"photosynthesis", "chlorophyll"}


def student_phrase(item: str) -> str:
    """'Mentions that BFS uses a queue (only partially evident)' -> 'BFS uses a queue'."""
    text = item.replace(PARTIAL_SUFFIX, "").replace(NO_DISCUSSION, "").strip().rstrip(".")
    return _lower_first(MARKER_VERBS.sub("", text).strip())


def _lower_first(text: str) -> str:
    """Lower-case the first letter unless the first word is an acronym (BFS, TCP)."""
    first = text.split(" ", 1)[0]
    return text if len(first) > 1 and first.isupper() else text[:1].lower() + text[1:]


def _content_words(text: str) -> set:
    return {w.lower() for w in re.findall(r"[A-Za-z][A-Za-z\-]{3,}", text or "")} - STOP


FEEDBACK_STOP = {"mention", "mentions", "explain", "explains", "describe", "describes", "state", "states", "identify", "identifies",
                 "give", "gives", "provide", "provides", "include", "includes", "define", "defines", "definition", "student", "answer",
                 "correct", "correctly", "clearly", "discuss", "discusses", "show", "shows", "list", "lists", "note", "notes"}


def _stem(word: str) -> str:
    """Light stemmer: scanning/scanned/scans -> scan, explores/explored -> explor, paths -> path."""
    w = word.lower()
    for suffix in ("ing", "ed", "es", "s"):
        if w.endswith(suffix) and not w.endswith("ss") and len(w) - len(suffix) >= 3:
            w = w[: -len(suffix)]
            break
    if len(w) >= 4 and w[-1] == w[-2] and w[-1] not in "aeiouls":
        w = w[:-1]  # scann -> scan, but keep "all", "class"
    if len(w) >= 4 and w.endswith("e"):
        w = w[:-1]  # update/updat, table/tabl, queue/queu
    return w[:6]


def _stems(text: str) -> set:
    """Stems of the content words, for the word-level check of semantic matches."""
    return {_stem(w) for w in re.findall(r"[A-Za-z][A-Za-z\-]{2,}", text or "")
            if w.lower() not in STOP and w.lower() not in FEEDBACK_STOP}


def _join(items: List[str]) -> str:
    if len(items) <= 1:
        return "".join(items)
    return "; ".join(items[:-1]) + "; and " + items[-1]


class FeedbackDrafter(_Base):
    def draft(self, question: str, criteria: List[Dict[str, Any]], answer: str, tone: str = "encouraging",
              expected_answer: Optional[str] = None, polish: Optional[bool] = None) -> Dict[str, Any]:
        question = (question or "").strip()
        answer = re.sub(r"\s+", " ", answer or "").strip()
        tone = tone if tone in TONES else "encouraging"
        criteria = [c for c in (criteria or []) if str(c.get("criterion") or "").strip()][:MAX_CRITERIA]
        if len(question) < 5:
            raise WritingToolError("Enter the question the student answered.")
        if not criteria:
            raise WritingToolError("Add at least one rubric criterion (what a good answer should contain).")
        if len(answer.split()) < 3:
            raise WritingToolError("The student answer is too short to give feedback on.")
        if len(answer) > MAX_ANSWER_CHARS:
            raise WritingToolError(f"The answer is {len(answer)} characters; the limit is {MAX_ANSWER_CHARS}.")

        analysis = self._analyse(question, criteria, answer, expected_answer)
        self._reconcile(analysis, criteria, answer)
        notes = self._notes(analysis, tone)
        template = self._template(notes, tone)

        # The small local model rewrites unreliably (it states things about the answer that are not true and drops
        # points), so by default the AI rewrite only runs on the hosted provider; the guards apply either way.
        if polish is None:
            polish = provider_context.requested_provider() == "hosted"
        text, method = template, "rubric_template"
        if polish and tone != "concise" and self.can_generate:
            polished = self._polish(notes, tone, answer, criteria)
            if polished:
                text, method = polished, "ai_polished"

        return {
            "feedback": text,
            "template_feedback": template,
            "tone": tone,
            "strengths": notes["strengths"],
            "improvements": notes["improvements"],
            "next_step": notes["next_step"],
            "criteria": [
                {
                    "criterion": r["criterion"],
                    "coverage": COVERAGE_LABELS.get(r["coverage_level"], r["coverage_level"]),
                    "coverage_level": r["coverage_level"],
                    "evidence": r["evidence"][:2],
                    "missing": [student_phrase(m) for m in r["missing_elements"]][:5],
                }
                for r in analysis["criterion_results"]
            ],
            "method": method,
            "evidence_method": analysis["metadata"].get("generation_method"),
            "model": self._model() if method == "ai_polished" else None,
            "note": "Draft feedback only: no marks are given or suggested. Check it against the answer and edit it before the student sees it.",
        }

    # ------------------------------------------------------------------ analysis

    def _analyse(self, question: str, criteria: List[Dict[str, Any]], answer: str, expected_answer: Optional[str]) -> Dict[str, Any]:
        """Run the grading engine with one nominal mark per criterion; only its evidence and coverage are used."""
        n = len(criteria)
        request = GradeAnswerRequest(
            student_answer={"text": answer},
            question={"text": question, "total_marks": n, "expected_answer": expected_answer},
            rubric={
                "total_marks": n,
                "criteria": [
                    {
                        "id": i + 1,
                        "criterion": str(c["criterion"]).strip()[:255],
                        "description": str(c.get("description") or "")[:2000],
                        "max_marks": 1,
                        "expected_indicators": c.get("indicators") or c.get("expected_indicators") or [],
                        "sort_order": i + 1,
                    }
                    for i, c in enumerate(criteria)
                ],
            },
        )
        # No text generator here: the engine's per-criterion sentence refinement is not needed for feedback
        return GradingEngine(hf_service=self.hf, text_generation_service=None).grade(request)

    @staticmethod
    def _reconcile(analysis: Dict[str, Any], criteria: List[Dict[str, Any]], answer: str) -> None:
        """Second, word-level check on the engine's semantic matches before anything is said to a student.

        MiniLM rates every sentence on the topic as fairly similar, so "explores level by level" can be counted as
        evidence for "finds the shortest path". Feedback that praises a point the student never made is worse than none:
        an indicator counts as covered only if the answer shares at least a third of its key words, and a sentence is
        quoted as a strength only if it shares at least two key words with that criterion.
        """
        answer_stems = _stems(answer)
        for r, c in zip(analysis["criterion_results"], criteria):
            indicators = list(c.get("indicators") or c.get("expected_indicators") or [])
            for ind in indicators:
                key = _stems(ind)
                if not key or len(key & answer_stems) / len(key) >= 1 / 3:
                    continue
                # Not supported by the answer's wording: report it as missing (also when the engine said "partly")
                r["missing_elements"] = [m for m in r["missing_elements"] if m != ind + PARTIAL_SUFFIX]
                if ind not in r["missing_elements"]:
                    r["missing_elements"].append(ind)
            criterion_stems = _stems(" ".join([str(c.get("criterion", "")), str(c.get("description") or "")] + indicators))
            r["evidence"] = [e for e in r["evidence"] if len(_stems(e) & criterion_stems) >= 2]
            fully_missing = sum(1 for m in r["missing_elements"] if not m.endswith(PARTIAL_SUFFIX))
            if indicators and fully_missing >= len(indicators):
                r["coverage_level"] = LEVEL_NONE if not r["evidence"] else LEVEL_LIMITED
            elif r["coverage_level"] == LEVEL_STRONG and fully_missing:
                r["coverage_level"] = LEVEL_PARTIAL
            if not r["evidence"] and r["coverage_level"] in (LEVEL_STRONG, LEVEL_PARTIAL):
                r["coverage_level"] = LEVEL_LIMITED

    def _notes(self, analysis: Dict[str, Any], tone: str) -> Dict[str, Any]:
        results = analysis["criterion_results"]
        levels = [r["coverage_level"] for r in results]
        strong = levels.count(LEVEL_STRONG)
        weak = sum(1 for lv in levels if lv in (LEVEL_NONE, LEVEL_LIMITED))
        overall = "strong" if strong == len(levels) or (strong * 3 >= len(levels) * 2 and LEVEL_NONE not in levels) \
            else ("weak" if weak * 2 > len(levels) else "mixed")

        strengths: List[Dict[str, str]] = []
        quoted: set = set()
        for r in results:
            fresh = [e for e in r["evidence"] if e not in quoted]
            if r["coverage_level"] in (LEVEL_STRONG, LEVEL_PARTIAL) and fresh:
                quote = fresh[0]
                quoted.add(quote)  # one sentence is praised once, not for every criterion it matched
                quote = (quote if len(quote) <= 140 else quote[:137].rsplit(" ", 1)[0] + "...").rstrip(".") or quote
                strengths.append({"criterion": r["criterion"], "quote": quote, "partial": r["coverage_level"] == LEVEL_PARTIAL})

        missing: List[str] = []
        develop: List[str] = []
        for r in results:
            for m in r["missing_elements"]:
                phrase = student_phrase(m)
                if not phrase:
                    continue
                target = develop if m.endswith(PARTIAL_SUFFIX) else missing
                if phrase not in missing and phrase not in develop:
                    target.append(phrase)

        limit = 1 if tone == "concise" else 3
        improvements = [f"Add: {p}" for p in missing[:limit]] + [f"Develop further: {p}" for p in develop[: max(0, limit - len(missing[:limit]))]]
        first = (missing or develop or [None])[0]
        next_step = f"Add a sentence or two on this point: {first}." if first else "Check your answer once more for accuracy and clear wording."
        return {
            "overall": overall,
            "opening": OPENINGS[tone][overall],
            "strengths": strengths[: (1 if tone == "concise" else 3)],
            "missing": missing[:limit],
            "develop": develop[: max(0, limit - len(missing[:limit]))],
            "improvements": improvements,
            "next_step": next_step,
        }

    # ------------------------------------------------------------------ writing

    @staticmethod
    def _template(notes: Dict[str, Any], tone: str) -> str:
        parts = [notes["opening"]]
        if notes["strengths"]:
            if tone == "concise":
                s = notes["strengths"][0]
                parts.append(f"Good: {_lower_first(s['criterion'])}.")
            else:
                done = [f"{_lower_first(s['criterion'])}{' (in part)' if s['partial'] else ''}, for example: \"{s['quote']}\"" for s in notes["strengths"]]
                parts.append("What you did well: " + _join(done) + ".")
        if notes["missing"]:
            parts.append(("Missing: " if tone == "concise" else "To improve, make sure your answer covers: ") + _join(notes["missing"]) + ".")
        if notes["develop"]:
            parts.append(("Expand: " if tone == "concise" else "Develop these points further: ") + _join(notes["develop"]) + ".")
        if tone != "concise" or not (notes["missing"] or notes["develop"]):
            parts.append("Next step: " + _lower_first(notes["next_step"]))
        return " ".join(parts)

    def _polish(self, notes: Dict[str, Any], tone: str, answer: str, criteria: List[Dict[str, Any]]) -> Optional[str]:
        done = "; ".join(f"{_lower_first(s['criterion'])} (\"{s['quote']}\")" for s in notes["strengths"]) or "nothing specific"
        lacking = "; ".join(notes["missing"] + notes["develop"]) or "none"
        prompt = (
            "Write short feedback to a student about their exam answer, using only these notes. Speak to the student as 'you'. "
            "Do not mention marks, scores or grades. Do not add facts that are not in the notes.\n\n"
            f"Notes:\n{EXAMPLE_NOTES}\nFeedback: {EXAMPLE_FEEDBACK}\n\n"
            f"Notes:\nTone: {tone}\nOpening: {notes['opening']}\nDone well: {done}\nMissing: {lacking}\nNext step: {notes['next_step']}\nFeedback:"
        )
        raw = self._gen(prompt, 220, completion=True) or ""
        text = re.split(r"\n\s*\n|\nNotes:", raw.strip())[0].strip()
        text = re.sub(r"^feedback:\s*", "", text, flags=re.I).strip()
        return text if self._acceptable(text, notes, answer, criteria) else None

    @staticmethod
    def _acceptable(text: str, notes: Dict[str, Any], answer: str, criteria: List[Dict[str, Any]]) -> bool:
        if not 60 <= len(text) <= 1500 or GRADE_WORDS.search(text):
            return False
        lower = text.lower()
        source = " ".join([answer, notes["opening"], notes["next_step"]] + [s["quote"] for s in notes["strengths"]]
                          + [str(c.get("criterion", "")) + " " + str(c.get("description", "")) + " " + " ".join(c.get("indicators") or []) for c in criteria])
        if any(w in lower and w not in source.lower() for w in EXAMPLE_ONLY_WORDS):
            return False  # copied the worked example
        if set(re.findall(r"\d+(?:\.\d+)?", text)) - set(re.findall(r"\d+(?:\.\d+)?", source)):
            return False  # invented a number
        # Every point the student must fix has to survive the rewrite
        for point in notes["missing"] + notes["develop"]:
            words = _content_words(point)
            if words and not any(w in lower or w[:5] in lower for w in words):
                return False
        return True
