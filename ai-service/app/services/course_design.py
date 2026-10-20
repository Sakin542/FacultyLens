"""AI-assisted course design: syllabus drafting (COs + CO->PO mapping + weekly outline) and lesson planning.

Course outcomes follow a Bloom progression sized to the course. CO->PO links come from MiniLM similarity between
the outcome statement and each program outcome (with a lexical fallback when the model is not loaded), and every
suggested link carries the evidence used. When a generation model is configured, CO statements are drafted by the
model and accepted only if they start with the intended Bloom verb; otherwise a template engine writes them.
Everything returned is a draft for faculty review.
"""

from __future__ import annotations

import math
import re
from typing import Any, Dict, List, Optional, Sequence, Tuple

STOPWORDS = {
    "a", "an", "the", "and", "or", "of", "to", "in", "on", "for", "with", "by", "from", "at", "as", "is", "are", "be",
    "this", "that", "these", "those", "into", "its", "their", "such", "using", "use", "including", "course", "students",
    "student", "will", "learn", "learning", "introduction", "introduces", "covers", "cover", "topics", "topic", "basic",
    "fundamental", "fundamentals", "principles", "concepts", "concept", "various", "different", "also", "both", "how",
}

BLOOM_ORDER = ["Remember", "Understand", "Apply", "Analyze", "Evaluate", "Create"]
PROGRESSIONS: Dict[int, List[str]] = {
    3: ["Understand", "Apply", "Analyze"],
    4: ["Understand", "Apply", "Analyze", "Create"],
    5: ["Understand", "Apply", "Analyze", "Evaluate", "Create"],
    6: ["Remember", "Understand", "Apply", "Analyze", "Evaluate", "Create"],
}
VERBS: Dict[str, List[str]] = {
    "Remember": ["Identify", "Describe", "Recall"],
    "Understand": ["Explain", "Describe", "Interpret"],
    "Apply": ["Apply", "Use", "Implement"],
    "Analyze": ["Analyze", "Compare", "Examine"],
    "Evaluate": ["Evaluate", "Justify", "Assess"],
    "Create": ["Design", "Develop", "Construct"],
}
TEMPLATES: Dict[str, str] = {
    "Remember": "{verb} the key terminology and facts of {topics}.",
    "Understand": "{verb} the core principles of {topics} and how they relate to each other.",
    "Apply": "{verb} {topics} to solve well-defined {course} problems.",
    "Analyze": "{verb} {topics} to identify trade-offs, limitations and causes of problems.",
    "Evaluate": "{verb} alternative approaches to {topics} against stated requirements and constraints.",
    "Create": "{verb} a complete {course} solution that integrates {topics}.",
}
ACTIVITIES: Dict[str, List[Tuple[str, str]]] = {
    "Remember": [("Retrieval practice", "Low-stakes recall quiz and flash-card review of key terms."), ("Concept map", "Students build a concept map linking the week's terms.")],
    "Understand": [("Think-pair-share", "Students explain the idea to a partner, then the class corrects misconceptions."), ("Worked example walkthrough", "Instructor narrates a worked example; students annotate each step's reasoning.")],
    "Apply": [("Guided problem set", "Students solve graded-difficulty problems with feedback after each one."), ("Lab exercise", "Hands-on lab applying the technique to a realistic dataset or system.")],
    "Analyze": [("Case study", "Groups dissect a real case, identify causes and compare alternative explanations."), ("Compare and contrast", "Students tabulate two approaches against criteria and present the trade-offs.")],
    "Evaluate": [("Structured debate", "Teams defend competing designs against explicit evaluation criteria."), ("Peer review", "Students critique each other's solutions with a rubric.")],
    "Create": [("Design studio", "Teams iterate on a design brief with mid-session critique."), ("Mini-project sprint", "Students build a working artefact and demo it at the end of the session.")],
}


def _cosine(a: Sequence[float], b: Sequence[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a)) or 1.0
    nb = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (na * nb)


def _words(text: str) -> List[str]:
    return [w for w in re.findall(r"[a-z][a-z0-9+\-#]*", (text or "").lower()) if w not in STOPWORDS and len(w) > 2]


def _jaccard(a: str, b: str) -> float:
    sa, sb = set(_words(a)), set(_words(b))
    return len(sa & sb) / len(sa | sb) if sa and sb else 0.0


def extract_topics(description: str, limit: int = 14) -> List[str]:
    """Topic phrases from a catalogue description: list items first, keyword bigrams as a fallback."""
    text = re.sub(r"\s+", " ", description or "").strip()
    pieces = re.split(r"[;,.:\n]|\band\b|\bincluding\b|\bsuch as\b|\bwith\b", text, flags=re.IGNORECASE)
    topics: List[str] = []
    seen = set()
    for piece in pieces:
        words = [w for w in re.findall(r"[A-Za-z][A-Za-z0-9+\-#/()]*", piece)]
        while words and words[0].lower() in STOPWORDS:
            words.pop(0)
        while words and words[-1].lower() in STOPWORDS:
            words.pop()
        if not 1 <= len(words) <= 6:
            continue
        phrase = " ".join(words)
        key = phrase.lower()
        # Short single words are noise unless they are acronyms (SQL, OOP, TCP)
        if key in seen or (len(words) == 1 and len(phrase) < 4 and not phrase.isupper()):
            continue
        seen.add(key)
        topics.append(phrase[0].upper() + phrase[1:])
        if len(topics) >= limit:
            break
    if len(topics) < 3:
        counts: Dict[str, int] = {}
        ws = _words(text)
        for w1, w2 in zip(ws, ws[1:]):
            counts[f"{w1} {w2}"] = counts.get(f"{w1} {w2}", 0) + 1
        for phrase, _ in sorted(counts.items(), key=lambda kv: (-kv[1], kv[0])):
            if phrase not in seen:
                topics.append(phrase.capitalize())
                seen.add(phrase)
            if len(topics) >= limit:
                break
    return topics


def _join(items: List[str]) -> str:
    items = [i.lower() if not re.search(r"[A-Z]{2}", i) else i for i in items]
    if len(items) <= 1:
        return items[0] if items else "the core topics"
    return ", ".join(items[:-1]) + " and " + items[-1]


class SyllabusDrafter:
    # Calibrated for MiniLM on short outcome statements: related CO/PO pairs typically score 0.25–0.55
    LINK_LEVELS = [(0.45, 3), (0.33, 2), (0.24, 1)]
    LEXICAL_LEVELS = [(0.20, 3), (0.12, 2), (0.06, 1)]

    def __init__(self, generation_service: Any = None, hf_service: Any = None) -> None:
        self.generation_service = generation_service
        self.hf_service = hf_service

    def _embed(self, texts: List[str]) -> Optional[List[List[float]]]:
        try:
            if self.hf_service is not None and self.hf_service.is_loaded and texts:
                return self.hf_service.generate_batch_embeddings(texts)
        except Exception:  # pragma: no cover
            return None
        return None

    def _model_statement(self, level: str, verb: str, topics: List[str], course: str) -> Optional[str]:
        gen = self.generation_service
        if gen is None or not getattr(gen, "is_configured", False):
            return None
        prompt = (f"Write one measurable course learning outcome for the university course '{course}'. "
                  f"It must start with the verb '{verb}' (Bloom level: {level}) and cover: {', '.join(topics)}. One sentence.")
        try:
            text = (gen.generate(prompt, max_new_tokens=60) or "").strip().strip('"')
        except Exception:  # pragma: no cover
            return None
        if not text.lower().startswith(verb.lower()) or not 6 <= len(text.split()) <= 40:
            return None
        return text if text.endswith(".") else text + "."

    def draft(self, course_code: str, course_name: str, description: str, credits: float = 3, weeks: int = 14,
              program_outcomes: Optional[List[Dict[str, str]]] = None, topics: Optional[List[str]] = None,
              outcome_count: Optional[int] = None) -> Dict[str, Any]:
        warnings: List[str] = []
        # The course title is not a teaching topic; it is only a fallback when the description names none
        topics = [t.strip() for t in (topics or []) if t and t.strip()] or extract_topics(description) or extract_topics(course_name)
        if len(topics) < 3:
            warnings.append("The description names few topics; add a fuller catalogue description or list topics for a better draft.")
        count = outcome_count or (5 if credits >= 3 else 4)
        count = max(3, min(6, count))
        progression = PROGRESSIONS[count]

        # Topics are taught roughly in description order: early topics feed the foundational outcomes
        chunks: List[List[str]] = [[] for _ in range(count)]
        for i, topic in enumerate(topics):
            chunks[min(count - 1, i * count // max(1, len(topics)))].append(topic)
        for i, chunk in enumerate(chunks):
            if not chunk:
                chunks[i] = topics[-2:] if i == count - 1 else topics[:2]

        used_model = False
        outcomes = []
        for i, level in enumerate(progression):
            verb = VERBS[level][i % len(VERBS[level])]
            chosen = chunks[i][:3]
            statement = self._model_statement(level, verb, chosen, course_name)
            if statement:
                used_model = True
            else:
                statement = TEMPLATES[level].format(verb=verb, topics=_join(chosen), course=course_name.lower())
            outcomes.append({"code": f"CO{i + 1}", "statement": statement, "cognitive_level": level, "topics": chunks[i]})

        mapping = self.map_outcomes(outcomes, program_outcomes or [])
        outline = self.weekly_outline(outcomes, weeks)
        plan = self.assessment_plan(outcomes)

        return {
            "course": {"code": course_code, "name": course_name},
            "topics": topics,
            "outcomes": outcomes,
            "co_po": mapping["links"],
            "co_po_method": mapping["method"],
            "weekly_outline": outline,
            "assessment_plan": plan,
            "engine": "model" if used_model else "template",
            "model": getattr(self.generation_service, "model_name", None) if used_model else None,
            "warnings": warnings + mapping["warnings"],
        }

    def map_outcomes(self, outcomes: List[Dict[str, Any]], pos: List[Dict[str, str]]) -> Dict[str, Any]:
        if not pos:
            return {"links": [], "method": "none", "warnings": ["No program outcomes were supplied, so no CO→PO mapping was suggested."]}
        po_texts = [f"{p.get('code', '')}: {p.get('title', '')}. {p.get('description', '')}".strip() for p in pos]
        co_texts = [o["statement"] for o in outcomes]
        vectors = self._embed(co_texts + po_texts)
        method = "embedding" if vectors else "lexical"
        levels = self.LINK_LEVELS if vectors else self.LEXICAL_LEVELS
        links = []
        for i, co in enumerate(outcomes):
            scored = []
            for j, po in enumerate(pos):
                score = _cosine(vectors[i], vectors[len(co_texts) + j]) if vectors else _jaccard(co_texts[i], po_texts[j])
                scored.append((score, j))
            scored.sort(reverse=True)
            made = 0
            for score, j in scored:
                level = next((lvl for threshold, lvl in levels if score >= threshold), 0)
                if level == 0:
                    continue
                shared = sorted(set(_words(co_texts[i])) & set(_words(po_texts[j])))[:4]
                links.append({
                    "co": co["code"], "po": pos[j].get("code"), "level": level, "similarity": round(score, 3),
                    "justification": f"{'Semantic' if vectors else 'Keyword'} match {score:.2f}" + (f"; shared focus: {', '.join(shared)}" if shared else ""),
                })
                made += 1
                if made >= 3:
                    break
            if made == 0 and scored:
                score, j = scored[0]
                links.append({"co": co["code"], "po": pos[j].get("code"), "level": 1, "similarity": round(score, 3),
                              "justification": f"Closest program outcome (weak match {score:.2f}); confirm or remove."})
        return {"links": links, "method": method, "warnings": [] if vectors else ["The embedding model is not loaded; CO→PO links use keyword overlap and need closer review."]}

    @staticmethod
    def weekly_outline(outcomes: List[Dict[str, Any]], weeks: int) -> List[Dict[str, Any]]:
        weeks = max(6, min(20, int(weeks)))
        mid = weeks // 2
        teaching = [w for w in range(1, weeks + 1) if w not in (mid, weeks)]
        slots: List[Tuple[str, str]] = [(t, o["code"]) for o in outcomes for t in o["topics"]] or [("Course introduction", outcomes[0]["code"])]
        outline = []
        parts: Dict[str, int] = {}
        for k, week in enumerate(teaching):
            topic, co = slots[min(len(slots) - 1, k * len(slots) // len(teaching))]
            parts[topic] = parts.get(topic, 0) + 1
            # A topic spread over several weeks is numbered so the outline reads as a progression
            label = topic if parts[topic] == 1 else f"{topic} (part {parts[topic]})"
            outline.append({"week": week, "topic": label, "outcomes": [co], "kind": "teaching"})
        outline.append({"week": mid, "topic": "Review and midterm examination", "outcomes": [o["code"] for o in outcomes[: max(1, len(outcomes) // 2)]], "kind": "exam"})
        outline.append({"week": weeks, "topic": "Project presentations, revision and final examination preparation", "outcomes": [o["code"] for o in outcomes], "kind": "review"})
        return sorted(outline, key=lambda w: w["week"])

    @staticmethod
    def assessment_plan(outcomes: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        by_level = lambda levels: [o["code"] for o in outcomes if o["cognitive_level"] in levels]  # noqa: E731
        lower = by_level({"Remember", "Understand", "Apply"})
        higher = by_level({"Analyze", "Evaluate", "Create"})
        return [
            {"component": "Quizzes (3)", "weight": 15, "outcomes": lower or [outcomes[0]["code"]]},
            {"component": "Assignments / project", "weight": 20, "outcomes": by_level({"Apply", "Create"}) or higher},
            {"component": "Midterm examination", "weight": 25, "outcomes": [o["code"] for o in outcomes[: max(1, len(outcomes) // 2 + 1)]]},
            {"component": "Final examination", "weight": 40, "outcomes": [o["code"] for o in outcomes]},
        ]


class LessonPlanner:
    def __init__(self, hf_service: Any = None) -> None:
        self.hf_service = hf_service

    def _embed(self, texts: List[str]) -> Optional[List[List[float]]]:
        try:
            if self.hf_service is not None and self.hf_service.is_loaded and texts:
                return self.hf_service.generate_batch_embeddings(texts)
        except Exception:  # pragma: no cover
            return None
        return None

    def plan(self, course_name: str, outcomes: List[Dict[str, Any]], weeks: int = 14, sessions_per_week: int = 2,
             session_minutes: int = 75, topics: Optional[List[str]] = None) -> Dict[str, Any]:
        warnings: List[str] = []
        if not outcomes:
            raise ValueError("At least one course outcome is required to plan lessons.")
        weeks = max(6, min(20, int(weeks)))
        sessions_per_week = max(1, min(5, int(sessions_per_week)))
        topics = [t for t in (topics or []) if t and t.strip()]
        if not topics:
            topics = extract_topics(" ; ".join(o.get("description", "") for o in outcomes))
            warnings.append("No weekly topics were given; topics were derived from the course outcomes. Edit them to match your syllabus.")

        mid = weeks // 2
        teaching_weeks = [w for w in range(1, weeks + 1) if w not in (mid, weeks)]
        base_topics = [topics[min(len(topics) - 1, k * len(topics) // len(teaching_weeks))] for k in range(len(teaching_weeks))]
        # Topics that span several weeks are numbered so each week reads as the next step
        week_topics, parts = [], {}
        for t in base_topics:
            t = re.sub(r"\s*\(part \d+\)$", "", t)
            parts[t] = parts.get(t, 0) + 1
            week_topics.append(t if parts[t] == 1 else f"{t} (part {parts[t]})")

        # Match each week's topic to the course outcomes it serves (semantic similarity, lexical fallback)
        co_texts = [f"{o.get('code')}: {o.get('description', '')}" for o in outcomes]
        vectors = self._embed(week_topics + co_texts)
        method = "embedding" if vectors else "lexical"
        weeks_out = []
        quiz_weeks = {w for w in teaching_weeks if w in (3, mid - 1, mid + 3, weeks - 2)}
        project_outcomes = [o["code"] for o in outcomes if (o.get("cognitive_level") or "").capitalize() in ("Apply", "Create")]
        assessments: List[Dict[str, Any]] = []

        for k, week in enumerate(teaching_weeks):
            topic = week_topics[k]
            if vectors:
                sims = [(_cosine(vectors[k], vectors[len(week_topics) + j]), j) for j in range(len(outcomes))]
            else:
                sims = [(_jaccard(topic, co_texts[j]), j) for j in range(len(outcomes))]
            sims.sort(reverse=True)
            chosen = [j for s, j in sims[:2] if s >= (0.3 if vectors else 0.05)] or [min(len(outcomes) - 1, k * len(outcomes) // len(teaching_weeks))]
            main = outcomes[chosen[0]]
            level = (main.get("cognitive_level") or "Understand").capitalize()
            level = level if level in ACTIVITIES else "Understand"
            activity = ACTIVITIES[level][k % len(ACTIVITIES[level])]

            sessions = [{"title": f"{topic}: core ideas", "kind": "lecture", "minutes": session_minutes,
                         "description": f"Interactive lecture on {topic.lower()} with two concept checks; link to {main['code']}."}]
            if sessions_per_week >= 2:
                sessions.append({"title": activity[0], "kind": "activity", "minutes": session_minutes, "description": activity[1]})
            for extra in range(2, sessions_per_week):
                sessions.append({"title": "Tutorial and practice", "kind": "tutorial", "minutes": session_minutes,
                                 "description": f"Practice problems on {topic.lower()} with individual feedback."})
            checks = ["Exit ticket: one-minute summary of the key idea"]
            if week in quiz_weeks:
                checks.append("Quiz on the topics since the last quiz")
                assessments.append({"week": week, "type": "quiz", "title": f"Quiz (week {week})", "outcomes": sorted({outcomes[j]["code"] for j in chosen})})

            weeks_out.append({
                "week": week, "kind": "teaching", "topic": topic,
                "outcomes": [outcomes[j]["code"] for j in chosen],
                "bloom_focus": level, "sessions": sessions, "formative_checks": checks,
                "preparation": f"Read the notes and one worked example on {topic.lower()} before the first session.",
            })

        if project_outcomes:
            release = teaching_weeks[min(len(teaching_weeks) - 1, 3)]
            due = teaching_weeks[-1]
            assessments.append({"week": release, "type": "assignment_release", "title": "Project brief released", "outcomes": project_outcomes})
            assessments.append({"week": due, "type": "assignment_due", "title": "Project due", "outcomes": project_outcomes})
        weeks_out.append({"week": mid, "kind": "exam", "topic": "Midterm review and examination", "outcomes": [o["code"] for o in outcomes[: max(1, len(outcomes) // 2)]],
                          "bloom_focus": None, "sessions": [{"title": "Guided review", "kind": "review", "minutes": session_minutes, "description": "Mixed practice across the first half; students self-assess against the outcomes."}],
                          "formative_checks": [], "preparation": "Revise weeks 1 to %d." % (mid - 1)})
        assessments.append({"week": mid, "type": "midterm", "title": "Midterm examination", "outcomes": [o["code"] for o in outcomes[: max(1, len(outcomes) // 2)]]})
        weeks_out.append({"week": weeks, "kind": "review", "topic": "Synthesis, presentations and final review", "outcomes": [o["code"] for o in outcomes],
                          "bloom_focus": None, "sessions": [{"title": "Project presentations", "kind": "activity", "minutes": session_minutes, "description": "Teams present; peers assess with the project rubric."}],
                          "formative_checks": [], "preparation": "Prepare questions for the final review."})
        assessments.append({"week": weeks, "type": "final", "title": "Final examination (exam period)", "outcomes": [o["code"] for o in outcomes]})

        coverage = {o["code"]: sum(1 for w in weeks_out if o["code"] in w["outcomes"] and w["kind"] == "teaching") for o in outcomes}
        for code, n in coverage.items():
            if n == 0:
                warnings.append(f"{code} is not the focus of any teaching week; add or retitle a topic for it.")

        return {
            "course": course_name, "weeks": sorted(weeks_out, key=lambda w: w["week"]),
            "assessments": sorted(assessments, key=lambda a: a["week"]), "outcome_coverage": coverage,
            "mapping_method": method, "warnings": warnings,
        }
