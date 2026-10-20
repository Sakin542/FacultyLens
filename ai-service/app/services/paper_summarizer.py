"""Research paper summarization (extractive, grounded in the paper's own sentences).

* Summary: sentences ranked by centrality (MiniLM similarity to the document centroid, or word-frequency salience
  when the model is not loaded) with an abstract/conclusion position bonus, then diversified with MMR so the
  summary does not repeat itself.
* Key findings / methods / limitations: cue-phrase detection within the matching sections, ranked by centrality.
* Metadata: title, authors, year, DOI and keywords from the first page; the paper's own citation (APA, IEEE).
* References: the bibliography is parsed into entries and ranked by how often the body cites them ("suggested
  citations" to follow up).

Nothing is invented: every summary line is a sentence from the paper.
"""

from __future__ import annotations

import math
import re
from typing import Any, Dict, List, Optional, Tuple

SECTION_RE = re.compile(
    r"^\s*(?:\d+(?:\.\d+)*\.?\s+|[IVX]+\.\s+)?(abstract|introduction|background|related work|literature review|method(?:s|ology)?|"
    r"materials and methods|approach|proposed (?:method|approach|system)|experiments?|experimental (?:setup|results)|evaluation|results?|"
    r"results and discussion|discussion|conclusions?|conclusion and future work|limitations|future work|acknowledge?ments?|references|bibliography)\s*:?\s*$",
    re.IGNORECASE | re.MULTILINE,
)
FINDING_CUES = re.compile(r"\b(results? (?:show|indicate|suggest|demonstrate)|we (?:find|found|show|demonstrate|observe|report)|outperform\w*|improv\w+ (?:by|of|over)|significant(?:ly)?|achiev\w+|reduc\w+ (?:by|of)|increas\w+ (?:by|of)|accuracy|f1|precision|recall|\d+(?:\.\d+)?\s?%|p\s?<\s?0?\.\d+)\b", re.IGNORECASE)
METHOD_CUES = re.compile(r"\b(we propose|we present|we introduce|our (?:approach|method|model|framework|system)|we (?:use|used|employ|train|collected|conducted|designed)|dataset|participants|survey|experiment(?:s|al)? (?:was|were)|is based on|consists of)\b", re.IGNORECASE)
LIMIT_CUES = re.compile(r"\b(limitation|limited|future work|future research|does not|do not|cannot|could not|only (?:a|one|two|small)|small sample|generaliz\w+|threat(?:s)? to validity|further (?:work|research|study))\b", re.IGNORECASE)
STOP = {"the", "a", "an", "and", "or", "of", "to", "in", "on", "for", "with", "by", "is", "are", "was", "were", "be", "been", "this", "that", "these", "we", "our", "it", "its", "as", "at", "from", "which", "can", "has", "have", "not", "also", "than", "their", "they", "such", "using", "use", "used", "based", "between", "into"}


def split_sentences(text: str) -> List[str]:
    text = re.sub(r"\s+", " ", text)
    # Avoid splitting on common abbreviations and decimal numbers
    protected = re.sub(r"\b(e\.g|i\.e|et al|Fig|Eq|vs|Dr|Mr|Ms|No|Vol|pp)\.", lambda m: m.group(0).replace(".", "§"), text)
    parts = re.split(r"(?<=[.!?])\s+(?=[A-Z(\[])", protected)
    return [p.replace("§", ".").strip() for p in parts if 40 <= len(p.strip()) <= 600]


def _tokens(s: str) -> List[str]:
    return [w for w in re.findall(r"[a-z][a-z\-]{2,}", s.lower()) if w not in STOP]


def _cosine(a: List[float], b: List[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a)) or 1.0
    nb = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (na * nb)


class PaperSummarizer:
    MAX_SENTENCES = 400

    def __init__(self, hf_service: Any = None) -> None:
        self.hf_service = hf_service

    # ------------------------------------------------------------------ structure

    def sections(self, text: str) -> Dict[str, str]:
        marks = [(m.start(), m.end(), m.group(1).lower()) for m in SECTION_RE.finditer(text)]
        if not marks:
            return {"body": text}
        out: Dict[str, str] = {}
        if marks[0][0] > 0:
            out["front"] = text[: marks[0][0]]
        for i, (_start, end, name) in enumerate(marks):
            stop = marks[i + 1][0] if i + 1 < len(marks) else len(text)
            key = self._canonical(name)
            out[key] = (out.get(key, "") + "\n" + text[end:stop]).strip()
        return out

    @staticmethod
    def _canonical(name: str) -> str:
        n = name.lower()
        if n.startswith("method") or n in ("materials and methods", "approach") or n.startswith("proposed"):
            return "methods"
        if n.startswith("experiment") or n == "evaluation":
            return "experiments"
        if n.startswith("result"):
            return "results"
        if n.startswith("conclusion"):
            return "conclusion"
        if n in ("related work", "literature review", "background"):
            return "related_work"
        if n in ("references", "bibliography"):
            return "references"
        if n.startswith("acknowled"):
            return "acknowledgements"
        if n in ("limitations", "future work"):
            return "limitations"
        return n

    # ------------------------------------------------------------------ metadata

    def metadata(self, text: str, title: Optional[str]) -> Dict[str, Any]:
        # Front matter only (before the first section heading): a year or DOI from the references is not the paper's
        first = SECTION_RE.search(text)
        front = text[: first.start()] if first and first.start() > 0 else text[:1500]
        abstract_end = SECTION_RE.search(text, first.end()) if first else None
        head = text[: abstract_end.start()] if abstract_end else front
        lines = [ln.strip() for ln in front.splitlines() if ln.strip()]
        detected_title = title or next((ln for ln in lines[:8] if 15 <= len(ln) <= 220 and not re.search(r"@|abstract|journal|vol\.|doi|http", ln, re.I)), None)
        authors: List[str] = []
        if detected_title and detected_title in lines:
            idx = lines.index(detected_title)
            for line in lines[idx + 1: idx + 4]:
                if re.search(r"abstract|university|department|institute|@|\d{4}", line, re.I):
                    break
                candidates = [a.strip(" *†‡0-9,") for a in re.split(r",| and |;", line) if a.strip()]
                names = [a for a in candidates if re.fullmatch(r"[A-Z][a-zA-Z.\-']+(?: [A-Z][a-zA-Z.\-']+){1,3}", a)]
                if names:
                    authors.extend(names)
        years = re.findall(r"\b(19[5-9]\d|20[0-4]\d)\b", front)
        doi = re.search(r"\b(10\.\d{4,9}/[^\s\"<>]+)", head)
        kw = re.search(r"(?:key\s?words|index terms)\s*[:—-]\s*(.+)", head, re.I)
        keywords = [k.strip(" .") for k in re.split(r"[;,·]", kw.group(1))][:8] if kw else []
        return {
            "title": detected_title,
            "authors": authors[:8],
            "year": int(years[0]) if years else None,
            "doi": doi.group(1).rstrip(".,;") if doi else None,
            "keywords": [k for k in keywords if 1 < len(k) < 60],
        }

    @staticmethod
    def citation(meta: Dict[str, Any]) -> Dict[str, Optional[str]]:
        if not meta.get("title"):
            return {"apa": None, "ieee": None}
        authors = meta.get("authors") or []

        def apa_name(n: str) -> str:
            parts = n.split()
            return f"{parts[-1]}, {' '.join(p[0] + '.' for p in parts[:-1])}" if len(parts) > 1 else n

        def ieee_name(n: str) -> str:
            parts = n.split()
            return f"{' '.join(p[0] + '.' for p in parts[:-1])} {parts[-1]}" if len(parts) > 1 else n

        year = meta.get("year") or "n.d."
        apa_authors = ", ".join(apa_name(a) for a in authors[:-1]) + (", & " if len(authors) > 1 else "") + (apa_name(authors[-1]) if authors else "")
        ieee_authors = ", ".join(ieee_name(a) for a in authors[:-1]) + (", and " if len(authors) > 1 else "") + (ieee_name(authors[-1]) if authors else "")
        doi = f" https://doi.org/{meta['doi']}" if meta.get("doi") else ""
        return {
            "apa": f"{apa_authors + ' ' if authors else ''}({year}). {meta['title']}.{doi}".strip(),
            "ieee": f"{ieee_authors + ', ' if authors else ''}\"{meta['title']},\" {year}{'' if str(year).endswith('.') else '.'}{(' doi: ' + meta['doi'] + '.') if meta.get('doi') else ''}".strip(),
        }

    # ------------------------------------------------------------------ references

    def references(self, ref_text: str, body: str) -> List[Dict[str, Any]]:
        if not ref_text.strip():
            return []
        raw = re.split(r"\n\s*(?=\[\d+\]|\d+\.\s+[A-Z])", "\n" + ref_text)
        entries = [re.sub(r"\s+", " ", e).strip() for e in raw if len(e.strip()) > 25]
        if len(entries) <= 1:
            entries = [re.sub(r"\s+", " ", e).strip() for e in re.split(r"\n(?=[A-Z][a-zA-Z\-']+,\s+[A-Z])", ref_text) if len(e.strip()) > 25]
        out = []
        for i, entry in enumerate(entries[:80]):
            num = re.match(r"\[?(\d+)[\].]", entry)
            year = re.search(r"\b(19[5-9]\d|20[0-4]\d)\b", entry)
            cited = 0
            if num:
                n = num.group(1)
                cited = len(re.findall(r"\[(?:[\d,\s–-]*\b)?" + n + r"(?:\b[\d,\s–-]*)?\]", body))
            else:
                surname = re.match(r"([A-Z][a-zA-Z\-']+)", entry)
                if surname and year:
                    cited = len(re.findall(re.escape(surname.group(1)) + r"[^()]{0,40}\(?" + year.group(1), body))
            out.append({"index": i + 1, "text": re.sub(r"^\[?\d+[\].]\s*", "", entry)[:400], "year": int(year.group(1)) if year else None, "cited_in_text": cited})
        out.sort(key=lambda r: (-r["cited_in_text"], -(r["year"] or 0)))
        return out[:15]

    # ------------------------------------------------------------------ summarize

    def summarize(self, text: str, title: Optional[str] = None, summary_sentences: int = 6) -> Dict[str, Any]:
        text = (text or "").replace("\r", "")
        if len(text.split()) < 80:
            raise ValueError("The document is too short to summarize (fewer than 80 words were extracted).")
        sections = self.sections(text)
        meta = self.metadata(text, title)
        body_keys = [k for k in sections if k not in ("references", "acknowledgements", "front")] or ["body"]

        sentences: List[Tuple[str, str]] = []
        for key in body_keys:
            for s in split_sentences(sections.get(key, "")):
                sentences.append((s, key))
        if not sentences:
            sentences = [(s, "body") for s in split_sentences(text)]
        sentences = sentences[: self.MAX_SENTENCES]
        texts = [s for s, _ in sentences]

        vectors = None
        try:
            if self.hf_service is not None and self.hf_service.is_loaded:
                vectors = self.hf_service.generate_batch_embeddings(texts)
        except Exception:  # pragma: no cover
            vectors = None

        if vectors:
            dim = len(vectors[0])
            centroid = [sum(v[d] for v in vectors) / len(vectors) for d in range(dim)]
            centrality = [_cosine(v, centroid) for v in vectors]
        else:
            freq: Dict[str, int] = {}
            for s in texts:
                for w in set(_tokens(s)):
                    freq[w] = freq.get(w, 0) + 1
            top = max(freq.values()) if freq else 1
            centrality = [sum(freq.get(w, 0) for w in set(_tokens(s))) / (top * max(1, len(set(_tokens(s)))) ** 0.5) for s in texts]
            hi = max(centrality) or 1
            centrality = [c / hi for c in centrality]

        bonus = {"abstract": 0.15, "conclusion": 0.12, "results": 0.06, "introduction": 0.03}
        score = [centrality[i] + bonus.get(sec, 0.0) for i, (_, sec) in enumerate(sentences)]

        def similarity(i: int, j: int) -> float:
            if vectors:
                return _cosine(vectors[i], vectors[j])
            a, b = set(_tokens(texts[i])), set(_tokens(texts[j]))
            return len(a & b) / len(a | b) if a and b else 0.0

        # MMR: relevance vs. redundancy
        chosen: List[int] = []
        candidates = sorted(range(len(texts)), key=lambda i: -score[i])[:80]
        while candidates and len(chosen) < summary_sentences:
            best = max(candidates, key=lambda i: 0.7 * score[i] - 0.3 * max((similarity(i, j) for j in chosen), default=0.0))
            chosen.append(best)
            candidates.remove(best)
        summary = [texts[i] for i in sorted(chosen)]

        # Claims about other people's work (introduction, related work) are never this paper's findings or limitations
        others = {"introduction", "related_work"}

        def cue_pick(pattern: "re.Pattern[str]", prefer: Tuple[str, ...], k: int, exclude: set, skip_sections: set) -> List[str]:
            ranked = []
            for i, (s, sec) in enumerate(sentences):
                if sec in skip_sections:
                    continue
                hits = len(pattern.findall(s))
                if hits and s not in exclude:
                    ranked.append((hits * 0.5 + score[i] + (0.3 if sec in prefer else 0.0), i))
            ranked.sort(reverse=True)
            picked, seen = [], set()
            for _, i in ranked:
                key = texts[i][:60]
                if key not in seen:
                    picked.append(texts[i])
                    seen.add(key)
                if len(picked) >= k:
                    break
            return picked

        findings = cue_pick(FINDING_CUES, ("results", "abstract", "conclusion", "experiments", "discussion"), 5, set(), others)
        methods = cue_pick(METHOD_CUES, ("methods", "experiments", "abstract"), 3, set(findings), others)
        limitations = cue_pick(LIMIT_CUES, ("limitations", "conclusion", "discussion"), 3, set(findings) | set(methods), others)

        body_text = "\n".join(sections.get(k, "") for k in body_keys)
        return {
            **meta,
            "summary": summary,
            "key_findings": findings,
            "methods": methods,
            "limitations": limitations,
            "sections_found": [k for k in sections if k not in ("front", "body")],
            "references": self.references(sections.get("references", ""), body_text),
            "citation": self.citation(meta),
            "stats": {"words": len(text.split()), "sentences": len(texts)},
            "engine": "embedding" if vectors else "frequency",
        }
