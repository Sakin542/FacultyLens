"""Generation-based teaching tools: study pack (notes + quiz), question quality check, simplify & explain, Bloom rewrite.

They reuse the shared GenerationService (local Qwen or the hosted provider) and the MiniLM embeddings, so they add
no new model memory. Small local models follow worked examples far better than instructions, so every prompt is
few-shot, and every output is validated before it is returned:

- quiz answers must be supported by a sentence of the source material (lexical + semantic match) or they are dropped,
  because small models state confident but wrong "facts";
- simplified text must actually read easier than the original or it is retried once and then flagged;
- Bloom rewrites must keep the original topic and are re-classified by the existing Bloom analyzer.

When generation is unavailable the tools fall back to extractive/rule-based results and say so in `method`.
Everything returned is a draft for faculty review.
"""

import math
import re
from typing import Any, Dict, List, Optional, Tuple

from app.services import claim_check
from app.services.cognitive_analyzer import CognitiveAnalyzer

BLOOM_LEVELS = ["REMEMBER", "UNDERSTAND", "APPLY", "ANALYZE", "EVALUATE", "CREATE"]

STOP = {"the", "a", "an", "and", "or", "of", "to", "in", "on", "for", "with", "by", "is", "are", "was", "were", "be", "been", "this", "that",
        "these", "those", "we", "our", "it", "its", "as", "at", "from", "which", "can", "has", "have", "not", "also", "than", "their", "they",
        "such", "so", "if", "then", "there", "you", "your", "i", "will", "would", "do", "does", "did", "what", "when", "how", "why", "about",
        "into", "very", "just", "like", "okay", "right", "now", "one", "two", "get", "got", "going", "all", "any", "more", "most", "some",
        "each", "other", "both", "use", "uses", "used", "using"}

# Words that do not identify what a question is about ("How does it work?")
GENERIC_QUESTION_WORDS = {"does", "work", "works", "mean", "means", "called", "another", "name", "difference", "differ", "example",
                          "examples", "explain", "describe", "define", "give", "list", "state", "following", "true", "false"}


class WritingToolError(Exception):
    """Bad input or a tool that needs a generation model when none is available (safe to show)."""


def split_sentences(text: str) -> List[str]:
    """Lenient sentence splitter (works on transcripts, which are often lower-case and loosely punctuated)."""
    text = re.sub(r"\s+", " ", text or "").strip()
    protected = re.sub(r"\b(e\.g|i\.e|et al|etc|vs|Dr|Mr|Ms|Fig|Eq)\.", lambda m: m.group(0).replace(".", "§"), text)
    parts = re.split(r"(?<=[.!?\u0964])\s+", protected)
    return [p.replace("§", ".").strip() for p in parts if 25 <= len(p.strip()) <= 500]


def _words(text: str, min_len: int = 3) -> set:
    return {w.lower() for w in re.findall(r"[A-Za-z][A-Za-z\-]{%d,}" % (min_len - 1), text or "")} - STOP


def _keywords(text: str, n: int = 12) -> List[str]:
    counts: Dict[str, int] = {}
    for w in re.findall(r"[A-Za-z][A-Za-z\-]{3,}", text):
        lw = w.lower()
        if lw not in STOP:
            counts[lw] = counts.get(lw, 0) + 1
    return [w for w, _ in sorted(counts.items(), key=lambda kv: (-kv[1], kv[0]))[:n]]


def _cosine(a: List[float], b: List[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    return dot / ((math.sqrt(sum(x * x for x in a)) or 1.0) * (math.sqrt(sum(y * y for y in b)) or 1.0))


def _clean_line(text: Optional[str]) -> str:
    """First meaningful line of a model reply, without labels, quotes or list markers."""
    for line in (text or "").splitlines():
        line = re.sub(r"^\s*(?:[-*•]|\d+[.)]|rewritten:|question:|simplified:|answer:)\s*", "", line.strip(), flags=re.I).strip().strip('"').strip()
        if line:
            return line
    return ""


def _syllables(word: str) -> int:
    word = word.lower()
    groups = re.findall(r"[aeiouy]+", word)
    n = len(groups) - (1 if word.endswith("e") and len(groups) > 1 else 0)
    return max(1, n)


def readability(text: str) -> Dict[str, Any]:
    """Flesch reading ease and Flesch-Kincaid grade (approximate; English text)."""
    words = re.findall(r"[A-Za-z]+", text or "")
    sentences = max(1, len(re.findall(r"[.!?]+", text or "")))
    if not words:
        return {"flesch_reading_ease": None, "grade_level": None, "words": 0}
    syl = sum(_syllables(w) for w in words)
    wps = len(words) / sentences
    spw = syl / len(words)
    return {
        "flesch_reading_ease": round(206.835 - 1.015 * wps - 84.6 * spw, 1),
        "grade_level": round(0.39 * wps + 11.8 * spw - 15.59, 1),
        "words": len(words),
    }


class _Base:
    def __init__(self, generation_service: Any = None, hf_service: Any = None) -> None:
        self.generation = generation_service
        self.hf = hf_service

    @property
    def can_generate(self) -> bool:
        return bool(self.generation and getattr(self.generation, "is_configured", False))

    def _gen(self, prompt: str, tokens: int, completion: bool = False) -> Optional[str]:
        if not self.can_generate:
            return None
        if completion:
            return self.generation.generate(prompt, max_new_tokens=tokens, completion=True)
        return self.generation.generate(prompt, max_new_tokens=tokens)

    def _model(self) -> Optional[str]:
        return getattr(self.generation, "model_name", None) if self.generation else None

    def _embed(self, texts: List[str]) -> List[List[float]]:
        if self.hf is None or not texts:
            return []
        try:
            return self.hf.generate_batch_embeddings(texts)
        except Exception:
            return []


# ============================================================================================== study pack

class StudyPackBuilder(_Base):
    """Lecture transcript or course material → summary, key points and a draft quiz grounded in the material."""

    MAX_CHARS = 60000
    CONTEXT_CHARS = 5000
    ANSWER_COVERAGE = 0.7   # share of the answer's content words found in the supporting span
    SUBJECT_COVERAGE = 0.5  # share of the question's subject words found in the same span

    def key_sentences(self, sentences: List[str], k: int, vectors: Optional[List[List[float]]] = None) -> List[str]:
        """Most central sentences (MiniLM similarity to the document centroid), in original order."""
        if len(sentences) <= k:
            return sentences
        vectors = vectors if vectors is not None else self._embed(sentences[:300])
        if vectors:
            dim = len(vectors[0])
            centroid = [sum(v[i] for v in vectors) / len(vectors) for i in range(dim)]
            scores = [_cosine(v, centroid) for v in vectors]
        else:  # keyword-overlap fallback
            kws = set(_keywords(" ".join(sentences), 20))
            scores = [len(kws & _words(s)) / (1 + len(s) / 200) for s in sentences[:300]]
        ranked = sorted(range(len(scores)), key=lambda i: -scores[i])[:k]
        return [sentences[i] for i in sorted(ranked)]

    def _summary(self, context: str) -> Optional[str]:
        prompt = (
            "Summarize lecture material for students in 3 to 4 plain sentences. Use only facts from the material.\n\n"
            "Material: Normalization organizes tables to reduce redundancy. First normal form removes repeating groups. "
            "Second normal form removes partial dependencies on a composite key. Third normal form removes transitive dependencies.\n"
            "Summary: Normalization structures database tables to cut redundant data. Each normal form removes one kind of "
            "dependency problem: 1NF removes repeating groups, 2NF partial key dependencies and 3NF transitive dependencies.\n\n"
            f"Material: {context}\nSummary:"
        )
        text = (self._gen(prompt, 180) or "").strip()
        text = re.split(r"\n\s*\n|\nMaterial:", text)[0].strip()
        return text if 40 <= len(text) <= 1500 else None

    @staticmethod
    def parse_qa(raw: str) -> List[Tuple[str, str]]:
        """Q/A pairs from either "Q: … | A: …" lines or a "Q: …" line followed by an "A: …" line."""
        pairs: List[Tuple[str, str]] = []
        pending: Optional[str] = None
        for line in (raw or "").splitlines():
            line = re.sub(r"^\s*\d+[.)]\s*", "", line.strip())
            same = re.match(r"^Q:\s*(.+?)\s*\|\s*A:\s*(.+)$", line, re.I)
            if same:
                pairs.append((same.group(1).strip(), same.group(2).strip()))
                pending = None
            elif re.match(r"^Q:", line, re.I):
                pending = re.sub(r"^Q:\s*", "", line, flags=re.I).strip()
            elif pending and re.match(r"^A:", line, re.I):
                pairs.append((pending, re.sub(r"^A:\s*", "", line, flags=re.I).strip()))
                pending = None
        return pairs

    @staticmethod
    def _stems(words: set) -> set:
        """Crude stems (first 5 letters) so plurals and verb forms still match: queue/queues, detect/detecting."""
        return {w[:5] for w in words}

    def support(self, question: str, answer: str, sentences: List[str]) -> Optional[str]:
        """The span of the material that supports this question/answer pair, or None.

        The word rule below decides first. For answers that are full statements (4+ words), the meaning check
        (claim_check) then rejects an answer a close sentence contradicts, and accepts a correct answer written in other
        words that the word rule missed. Short answers ("A queue") are left to the word rule: NLI cannot judge fragments.
        """
        span = self._word_support(question, answer, sentences)
        if len(_words(answer)) < 4 or not sentences:
            return span
        passages = sentences + [f"{sentences[i]} {sentences[i + 1]}" for i in range(len(sentences) - 1)]
        claim = claim_check.expand(answer.strip(), claim_check.abbreviations(sentences))
        judged = claim_check.get_claim_verifier().judge([claim], passages)
        if not judged:
            return span
        j = judged[0]
        if j.contradiction >= claim_check.CONTRADICTION:
            return None
        if span is None and (j.entailment >= claim_check.ENTAILMENT or j.similarity >= claim_check.SIMILARITY):
            return j.passage
        return span

    def _word_support(self, question: str, answer: str, sentences: List[str]) -> Optional[str]:
        """The 1–2 sentence span of the material that supports this question/answer pair, or None.

        Small models state confident but wrong "facts", often by swapping two concepts that appear in the same lecture
        ("depth-first search explores level by level"). Similarity scores cannot catch that, so a pair is accepted only
        when the answer's content words AND the question's subject occur together in one short span of the material.
        """
        sentence_stems = [self._stems(_words(s)) for s in sentences]
        claims = [c for c in re.split(r"(?<=[.!?])\s+", answer.strip()) if _words(c) or re.search(r"\d", c)]
        if len(claims) > 1 and all(len(_words(c)) >= 4 for c in claims):
            # Multi-sentence answer: every claim must be supported on its own (each with its own concept terms), so a
            # correct comparison passes while one swapped claim inside it still rejects the whole pair
            spans = [self._best_span(set(), self._stems(_words(c)), set(re.findall(r"\d+(?:\.\d+)?", c)), sentences, sentence_stems, require_own_concepts=True) for c in claims]
            return None if any(s is None for s in spans) else " … ".join(dict.fromkeys(spans))

        answer_words = self._stems(_words(answer))
        numbers = set(re.findall(r"\d+(?:\.\d+)?", answer))
        if not answer_words and not numbers:
            return None
        subject = self._stems(_words(question, 4) - GENERIC_QUESTION_WORDS) - answer_words
        return self._best_span(subject, answer_words, numbers, sentences, sentence_stems, require_own_concepts=False)

    def _concepts(self, stems: set, sentence_stems: List[set]) -> set:
        """Concept terms: words that recur in the material but are not everywhere ("breadth-first" in a lecture where
        "search" and "graph" occur in almost every sentence)."""
        limit = max(2, int(0.4 * len(sentence_stems)))
        return {w for w in stems if 2 <= sum(1 for st in sentence_stems if w in st) <= limit}

    def _best_span(self, subject: set, answer_words: set, numbers: set, sentences: List[str], sentence_stems: List[set], require_own_concepts: bool) -> Optional[str]:
        # Every concept term must be inside the span: this is what rejects swapped facts such as attributing a
        # depth-first property to breadth-first search
        concepts = self._concepts(answer_words if require_own_concepts else subject, sentence_stems)
        best, best_score = None, 0.0
        for i in range(len(sentences)):
            for width in (1, 2):
                if i + width > len(sentences):
                    continue
                span = " ".join(sentences[i:i + width])
                span_words = set().union(*sentence_stems[i:i + width])
                total = len(answer_words) + len(numbers)
                answer_cov = (len(answer_words & span_words) + len(numbers & set(re.findall(r"\d+(?:\.\d+)?", span)))) / total if total else 0.0
                subject_cov = len(subject & span_words) / len(subject) if subject else 1.0
                subject_ok = concepts <= span_words if concepts else subject_cov >= self.SUBJECT_COVERAGE
                if answer_cov >= self.ANSWER_COVERAGE and subject_ok:
                    score = answer_cov + subject_cov - 0.05 * (width - 1)  # prefer the tightest span
                    if score > best_score:
                        best, best_score = span, score
        return best

    def _quiz(self, context: str, sentences: List[str], vectors: List[List[float]], n: int) -> Tuple[List[Dict[str, Any]], int]:
        prompt = (
            "Write short-answer quiz questions that can be answered from the material. Use the exact facts in the material.\n\n"
            "Material: A stack is a last-in first-out structure. Push adds to the top and pop removes from the top. "
            "A queue is first-in first-out.\n"
            "Q: In what order does a stack remove elements?\nA: Last in, first out (LIFO).\n"
            "Q: Which stack operation adds an element to the top?\nA: Push.\n\n"
            f"Material: {context}\nQ:"
        )
        # The prompt ends with "Q:" so the model continues straight into the first question
        raw = self._gen(prompt, 70 * (n + 3), completion=True)
        raw = ("Q: " + raw) if raw else ""
        # Small models keep going and invent a new "Material:" block with questions about it; drop everything after it
        raw = re.split(r"\n\s*Material:", raw)[0]
        quiz: List[Dict[str, Any]] = []
        rejected = 0
        for q, a in self.parse_qa(raw):
            if len(q) < 15 or len(q) > 300 or not a or any(x["question"].lower() == q.lower() for x in quiz):
                rejected += 1
                continue
            if not (_words(q, 4) - GENERIC_QUESTION_WORDS):  # subject is only a pronoun: "How does it work?"
                rejected += 1
                continue
            evidence = self.support(q, a, sentences)
            if evidence is None:
                rejected += 1
                continue
            quiz.append({"question": q if q.endswith("?") else q.rstrip(".") + "?", "answer": a, "type": "short_answer",
                         "bloom_level": CognitiveAnalyzer.analyze(q).get("level"), "evidence": evidence})
            if len(quiz) >= n:
                break
        return quiz, rejected

    FILLER_START = re.compile(r"^(today|now|so|okay|ok|in this|in the next|next time|let's|let us|we are going|we will|remember that)\b", re.I)
    FILLER_TERMS = {"before", "after", "next", "first", "second", "different", "differently", "possible", "number", "proportional", "lecture",
                    "today", "means", "level", "levels", "order", "thing", "things", "point", "points", "example", "examples", "important"}

    def cloze_quiz(self, sentences: List[str], n: int, exclude: Optional[set] = None) -> List[Dict[str, Any]]:
        """Template items: blank out a concept term in a key sentence (always grounded in the material).

        Blanks only concept terms (recurring, but not in every sentence), never filler words, never the same answer twice,
        and never from discourse sentences such as "Today we are going to talk about …".
        """
        sentence_stems = [self._stems(_words(s)) for s in sentences]
        limit = max(2, int(0.4 * len(sentences)))
        df: Dict[str, int] = {}
        for st in sentence_stems:
            for w in st:
                df[w] = df.get(w, 0) + 1
        used = {a.lower() for a in (exclude or set())}
        quiz: List[Dict[str, Any]] = []
        for sentence in self.key_sentences(sentences, min(len(sentences), 3 * n + 3)):
            if self.FILLER_START.search(sentence) or sentence in (exclude or set()):
                continue
            candidates = [w for w in re.findall(r"[A-Za-z][A-Za-z\-]{4,}", sentence)
                          if w.lower() not in STOP and w.lower() not in self.FILLER_TERMS and w.lower() not in used
                          and 1 <= df.get(w.lower()[:5], 0) <= limit]
            if not candidates:
                continue
            # Prefer hyphenated / multi-part technical terms, then the rarest, then the longest
            term = sorted(candidates, key=lambda w: (-("-" in w), df.get(w.lower()[:5], 0), -len(w)))[0]
            m = re.search(rf"\b{re.escape(term)}\b", sentence)
            if not m:
                continue
            used.add(term.lower())
            quiz.append({"question": "Fill in the blank: " + sentence[:m.start()] + "_____" + sentence[m.end():], "answer": term,
                         "type": "fill_in_blank", "bloom_level": "REMEMBER", "evidence": sentence})
            if len(quiz) >= n:
                break
        return quiz

    def build(self, text: str, title: Optional[str] = None, num_questions: int = 5) -> Dict[str, Any]:
        text = (text or "").strip()
        if len(text) < 200:
            raise WritingToolError("Provide at least a few paragraphs of material (200+ characters).")
        if len(text) > self.MAX_CHARS:
            raise WritingToolError(f"The material is {len(text)} characters; the limit is {self.MAX_CHARS}.")
        num_questions = max(1, min(10, int(num_questions)))
        sentences = split_sentences(text)[:300]
        if len(sentences) < 3:
            raise WritingToolError("Could not find enough complete sentences to work with.")

        vectors = self._embed(sentences)
        points = self.key_sentences(sentences, 7, vectors)
        keywords = _keywords(text)
        # Long material is reduced to its most central sentences before generation (small models, CPU time)
        context = " ".join(self.key_sentences(sentences, 25, vectors))[: self.CONTEXT_CHARS]

        summary = self._summary(context)
        quiz, rejected = self._quiz(context, sentences, vectors, num_questions)
        quiz_method = "generative" if quiz else "template"
        if len(quiz) < num_questions:
            used = {q.get("evidence") for q in quiz}
            # Exclude sentences already used as evidence and answers already asked for
            quiz += self.cloze_quiz(sentences, num_questions - len(quiz), exclude=used | {q["answer"] for q in quiz})
        return {
            "title": (title or "").strip() or None,
            "summary": summary or " ".join(points[:3]),
            "summary_method": "generative" if summary else "extractive",
            "key_points": points,
            "keywords": keywords,
            "quiz": quiz,
            "quiz_method": quiz_method,
            "quiz_rejected": rejected,
            "model": self._model() if (summary or quiz_method == "generative") else None,
            "note": "Draft study material. Each quiz answer is matched to a sentence in your material (shown as evidence) and AI answers "
                    "that the material does not support were removed. Still check everything before sharing it with students.",
        }


# ============================================================================================== question quality

VAGUE_VERBS = re.compile(r"\b(know|understand|appreciate|be aware of|learn|familiar with|grasp)\b", re.I)
VAGUE_WORDS = re.compile(r"\b(some|several|various|etc\.?|and so on|things?|stuff|briefly|a few|many|often|usually|sometimes|properly|in detail)\b", re.I)
NEGATIVE = re.compile(r"\b(not|never|no|none|except|neither|nor|cannot|isn't|aren't|doesn't|don't)\b", re.I)
COMMAND = re.compile(r"^\s*(?:(?:q\d+[.:)]?|\d+[.:)])\s*)?(?:define|state|list|name|explain|describe|discuss|compare|contrast|calculate|compute|solve|write|draw|design|evaluate|justify|analy[sz]e|prove|derive|show|find|determine|outline|illustrate|identify|differentiate|construct|develop|apply|implement|create|critique|summari[sz]e|give|mention|classify|distinguish|demonstrate|assess|insert|trace)\b", re.I)


class QuestionQualityChecker(_Base):
    MAX_QUESTIONS = 15

    def issues(self, q: str) -> List[Dict[str, str]]:
        found: List[Dict[str, str]] = []
        text = q.strip()
        words = re.findall(r"\w+", text)

        def add(code: str, severity: str, message: str) -> None:
            found.append({"code": code, "severity": severity, "message": message})

        if len(words) < 4:
            add("too_short", "high", "Very short: students may not know what is expected.")
        if len(words) > 70:
            add("too_long", "medium", "Long question: consider splitting it or moving context into a stem.")
        if text.count("?") > 1 or re.search(r"\?\s*(and|also)\b", text, re.I):
            add("multiple_questions", "medium", "Asks more than one thing; split it so each part can be marked separately.")
        if len(NEGATIVE.findall(text)) >= 2:
            add("double_negative", "high", "Several negatives make the question easy to misread.")
        elif re.search(r"\b(NOT|EXCEPT)\b", text) is None and re.search(r"\b(not|except)\b", text):
            add("hidden_negative", "low", "Contains a negative ('not'/'except'); consider emphasising it (NOT, EXCEPT).")
        if VAGUE_VERBS.search(text):
            add("vague_verb", "medium", "Uses a verb that cannot be observed (know, understand, appreciate); use a measurable verb such as explain, compare or calculate.")
        vague = sorted({m.group(0).lower() for m in VAGUE_WORDS.finditer(text)})
        if vague:
            add("vague_wording", "low", f"Vague wording ({', '.join(vague)}); say exactly how many points or how much detail you expect.")
        if re.search(r"\ball of the above\b|\bnone of the above\b", text, re.I):
            add("all_none_above", "medium", "'All/None of the above' options weaken multiple-choice items.")
        if not text.endswith("?") and not COMMAND.search(text):
            add("unclear_task", "medium", "Not phrased as a question or an instruction; start with a task verb or end with a question mark.")
        if re.search(r"(?<![A-Za-z])i(?![A-Za-z'])", text) or re.search(r"\s[,.]", text) or (text and text[0].islower()):
            add("grammar", "low", "Capitalisation or punctuation looks off.")
        return found

    def rewrite(self, q: str) -> Optional[str]:
        prompt = (
            "Task: rewrite an exam question so it is clear, specific and grammatical. Keep the same topic and difficulty. Never answer it.\n\n"
            "Original: explain sorting\n"
            "Rewritten: Explain how merge sort divides and merges an array, and state its time complexity.\n\n"
            "Original: what is normalization why needed\n"
            "Rewritten: Define database normalization and explain why it is needed to reduce data redundancy.\n\n"
            "Original: Do you not know which of the following is not a sorting algorithm?\n"
            "Rewritten: Which of the following is NOT a sorting algorithm?\n\n"
            f"Original: {q}\nRewritten:"
        )
        out = _clean_line(self._gen(prompt, 80))
        # Reject replies that answer instead of rewriting, drift off topic or barely change anything
        if not out or len(out) < 8 or len(out) > max(300, len(q) * 3) or out.lower() == q.strip().lower():
            return None
        topic = _words(q) - GENERIC_QUESTION_WORDS
        if topic and not (topic & _words(out)) and not any(t[:3] in out.lower() for t in topic):
            return None
        return out

    def check(self, questions: List[str], suggest_rewrites: bool = True) -> Dict[str, Any]:
        questions = [q.strip() for q in questions if q and q.strip()]
        if not questions:
            raise WritingToolError("Enter at least one question.")
        if len(questions) > self.MAX_QUESTIONS:
            raise WritingToolError(f"Check up to {self.MAX_QUESTIONS} questions at a time.")
        weight = {"high": 3, "medium": 2, "low": 1}
        results = []
        for q in questions:
            issues = self.issues(q)
            rewrite = self.rewrite(q) if suggest_rewrites and issues and self.can_generate else None
            results.append({
                "question": q,
                "score": max(0, 100 - sum(weight[i["severity"]] * 8 for i in issues)),
                "issues": issues,
                "readability": readability(q),
                "bloom_level": CognitiveAnalyzer.analyze(q).get("level"),
                "suggested_rewrite": rewrite,
                "rewrite_bloom_level": CognitiveAnalyzer.analyze(rewrite).get("level") if rewrite else None,
            })
        return {
            "results": results,
            "rewrites_available": self.can_generate,
            "model": self._model() if any(r["suggested_rewrite"] for r in results) else None,
            "note": "Checks are rule-based; rewrites are AI suggestions. You decide what to change.",
        }


# ============================================================================================== simplify & explain

LEVELS = {
    "school": {
        "guide": "a first-year student who is new to the subject. Use very short sentences (under 15 words), everyday words and one idea per sentence",
        "example": ("Deadlock occurs when each process in a set holds a resource while waiting for another resource held by a different process in the set.",
                    "A deadlock is when programs get stuck. Each program holds one thing. Each one waits for a thing another program holds. So none of them can move on."),
    },
    "undergraduate": {
        "guide": "an undergraduate student. Use clear academic English, keep sentences short and define each technical term the first time you use it",
        "example": ("Deadlock occurs when each process in a set holds a resource while waiting for another resource held by a different process in the set.",
                    "A deadlock happens when several processes each hold a resource and wait for a resource that another process holds. Because every process is waiting, none of them can continue."),
    },
    "esl": {
        "guide": "a student who is learning English. Use simple words, short sentences and no idioms",
        "example": ("Deadlock occurs when each process in a set holds a resource while waiting for another resource held by a different process in the set.",
                    "In a deadlock, programs stop. Each program has one thing. Each program waits for a thing from another program. No program can go on."),
    },
}


class Simplifier(_Base):
    MAX_CHARS = 3000

    def _attempt(self, text: str, level: str, strict: bool) -> str:
        cfg = LEVELS[level]
        guide = cfg["guide"] + (". Use even shorter sentences and simpler words than the original" if strict else "")
        prompt = (
            f"Rewrite the text for {guide}. Keep every fact. Do not add new facts.\n\n"
            f"Text: {cfg['example'][0]}\nRewritten: {cfg['example'][1]}\n\n"
            f"Text: {text}\nRewritten:"
        )
        out = (self._gen(prompt, min(600, 60 + len(text) // 2)) or "").strip()
        return re.split(r"\n\s*\n|\nText:", out)[0].strip()

    @staticmethod
    def _easier(before: Dict[str, Any], after: Dict[str, Any]) -> bool:
        if before["grade_level"] is None or after["grade_level"] is None:
            return True  # cannot measure (e.g. non-English text)
        return after["grade_level"] <= before["grade_level"] - 1 or (after["flesch_reading_ease"] or 0) >= (before["flesch_reading_ease"] or 0) + 10

    def simplify(self, text: str, level: str = "undergraduate") -> Dict[str, Any]:
        text = (text or "").strip()
        if len(text) < 40:
            raise WritingToolError("Paste a sentence or paragraph to simplify (40+ characters).")
        if len(text) > self.MAX_CHARS:
            raise WritingToolError(f"The text is {len(text)} characters; the limit is {self.MAX_CHARS}.")
        if level not in LEVELS:
            raise WritingToolError("Choose a reading level.")
        if not self.can_generate:
            raise WritingToolError("Simplify needs a generation model. Ask the administrator to configure one.")

        before = readability(text)
        out = self._attempt(text, level, strict=False)
        after = readability(out)
        if len(out) >= 20 and not self._easier(before, after):
            retry = self._attempt(text, level, strict=True)
            retry_after = readability(retry)
            if len(retry) >= 20 and (self._easier(before, retry_after) or (retry_after["grade_level"] or 99) < (after["grade_level"] or 99)):
                out, after = retry, retry_after
        if len(out) < 20:
            raise WritingToolError("The model could not produce a usable rewrite. Try a shorter passage.")
        simpler = self._easier(before, after)
        return {
            "text": out,
            "level": level,
            "readability_before": before,
            "readability_after": after,
            "simpler": simpler,
            "terms": [w for w in _keywords(text, 8) if len(w) >= 7][:6],
            "model": self._model(),
            "note": "AI rewrite. Check that no meaning was lost before giving it to students."
                    + ("" if simpler else " The rewrite is not measurably easier to read than the original."),
        }


# ============================================================================================== Bloom rewriter

# Two worked examples per level, on different topics, so the model copies the pattern rather than the topic
BLOOM_EXAMPLES = {
    "REMEMBER": [("Explain how a hash table handles collisions.", "List two methods a hash table uses to handle collisions."),
                 ("Compare TCP and UDP.", "State the transport-layer protocol that guarantees in-order delivery.")],
    "UNDERSTAND": [("Define a hash table.", "Explain how a hash table stores and finds a value using a hash function."),
                   ("List the layers of the OSI model.", "Describe what the transport layer of the OSI model is responsible for.")],
    "APPLY": [("Define linear probing.", "Insert the keys 12, 22 and 32 into a hash table of size 10 using linear probing and show the final table."),
              ("Define subnetting.", "Calculate the number of usable hosts in the subnet 192.168.1.0/26.")],
    "ANALYZE": [("Define linear probing.", "Compare linear probing and separate chaining in terms of clustering and memory use."),
                ("Define TCP.", "Contrast TCP and UDP for a live video-streaming application.")],
    "EVALUATE": [("List two collision-handling methods.", "Justify which collision-handling method you would choose for a hash table that is 90% full."),
                 ("Define UDP.", "Assess whether UDP is a suitable protocol for online banking transactions and justify your answer.")],
    "CREATE": [("Explain linear probing.", "Design a hash table for a phone book of 10,000 entries and describe how it handles collisions."),
               ("Describe a subnet.", "Design an IP addressing plan for a campus with four departments of 50 hosts each.")],
}
LEVEL_VERBS = {"REMEMBER": "List / State / Define", "UNDERSTAND": "Explain / Describe", "APPLY": "Calculate / Apply / Solve / Trace",
               "ANALYZE": "Compare / Contrast / Analyze", "EVALUATE": "Justify / Evaluate / Assess", "CREATE": "Design / Construct / Propose"}


class BloomRewriter(_Base):
    ATTEMPTS = 3

    def rewrite(self, question: str, target: str) -> Dict[str, Any]:
        question = (question or "").strip()
        target = (target or "").upper()
        if len(question) < 10:
            raise WritingToolError("Enter the question to rewrite.")
        if target not in BLOOM_LEVELS:
            raise WritingToolError("Choose a Bloom level.")
        if not self.can_generate:
            raise WritingToolError("The Bloom rewriter needs a generation model. Ask the administrator to configure one.")

        original_level = CognitiveAnalyzer.analyze(question).get("level")
        topic = _words(question, 4) - GENERIC_QUESTION_WORDS
        (a_from, a_to), (b_from, b_to) = BLOOM_EXAMPLES[target]
        attempts: List[Dict[str, Any]] = []
        for i in range(self.ATTEMPTS):
            hint = "" if i == 0 else f" Start with one of these verbs: {LEVEL_VERBS[target]}. Keep the topic: {', '.join(sorted(topic)) or question}."
            prompt = (
                f"Rewrite the exam question so it tests the {target.title()} level of Bloom's taxonomy. Keep exactly the same topic. Never answer it.{hint}\n\n"
                f"Original: {a_from}\nRewritten: {a_to}\n\n"
                f"Original: {b_from}\nRewritten: {b_to}\n\n"
                f"Original: {question}\nRewritten:"
            )
            out = _clean_line(self._gen(prompt, 90))
            if not out:
                continue
            level = CognitiveAnalyzer.analyze(out).get("level")
            on_topic = not topic or bool(topic & _words(out)) or any(t[:4] in out.lower() for t in topic)
            attempts.append({"text": out, "level": level, "on_topic": on_topic})
            if level == target and on_topic:
                break
        if not attempts:
            raise WritingToolError("The model could not produce a rewrite. Try again.")
        best = next((a for a in attempts if a["level"] == target and a["on_topic"]), None) \
            or next((a for a in attempts if a["on_topic"]), None) or attempts[-1]
        return {
            "original": question,
            "original_level": original_level,
            "target_level": target,
            "rewritten": best["text"],
            "detected_level": best["level"],
            "matches_target": best["level"] == target,
            "on_topic": best["on_topic"],
            "attempts": len(attempts),
            "model": self._model(),
            "note": "The detected level comes from FacultyLens's verb-based Bloom classifier; confirm the cognitive demand yourself.",
        }
