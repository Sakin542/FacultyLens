"""Meaning-level check of a generated claim against source passages.

Word overlap alone (the original check) accepts a sentence that reuses a passage's words with one fact swapped
("BFS uses a stack", "the final exam is worth 40 percent") and rejects a correct sentence written in other words.
This adds two signals:

- similarity : MiniLM cosine between the claim and its closest passages (already loaded for retrieval);
- NLI        : a cross-encoder (cross-encoder/nli-deberta-v3-base) reads passage + claim and scores whether the
               passage entails or contradicts the claim. It is very reliable at contradictions (swapped terms, wrong
               numbers, wrong comparisons) and weaker at confirming paraphrases, which is why similarity is used too.

Local decision (calibrated on reworded and subtly wrong claims about course material, see docs/AI_TOOLKIT.md):
  the passage closest in meaning contradicts it (>= 0.8) and no passage entails it -> unsupported, whatever the words
  else words mostly found in one passage, or entailed (>= 0.8), or very close in meaning (>= 0.80)  -> supported
Only the closest passage can veto: NLI also scores unrelated passages as "contradiction", so taking the strongest
contradiction over several passages rejected correct claims.

Two additions cover what the local models miss:
- abbreviations are expanded from the documents themselves ("Breadth-first search (BFS)" makes "BFS" in a claim read
  as "breadth-first search (BFS)"), before every check;
- with the hosted provider selected, judge_with_llm() asks a hosted model (Claude Haiku by default) for a verdict per
  claim: SUPPORTED, CONTRADICTED or NOT_FOUND, and that verdict decides. It understands heavy rewording ("one fifth
  of the grade" for 20 percent, where NLI wrongly sees a contradiction) and invented details that contradict nothing:
  12/12 on the calibration claims in two runs, against 10/12 for the local models.

If the NLI model cannot be loaded, judge() returns None and callers keep the word-overlap rule. The NLI model is
small (~0.9 GB in memory), loads on first use and is unloaded when idle (model_memory).
"""

import json
import logging
import re
import threading
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

from app.config import get_settings
from app.services import llm_chain, model_memory, provider_context

logger = logging.getLogger("facultylens.ai")

CONTRADICTION = 0.8
ENTAILMENT = 0.8
SIMILARITY = 0.80
TOP_PASSAGES = 3


@dataclass
class Judgement:
    entailment: float
    contradiction: float
    similarity: float
    passage: str

    def supported(self, lexical_ok: bool) -> bool:
        if self.contradiction >= CONTRADICTION and self.entailment < ENTAILMENT:
            return False
        return lexical_ok or self.entailment >= ENTAILMENT or self.similarity >= SIMILARITY


class ClaimVerifier:
    _lock = threading.Lock()
    _model: Any = None
    _labels: Optional[List[str]] = None
    _failed: bool = False

    @property
    def model_name(self) -> str:
        return (get_settings().claim_check_model or "").strip()

    @property
    def enabled(self) -> bool:
        return bool(self.model_name) and not ClaimVerifier._failed

    @staticmethod
    def _unload() -> None:
        ClaimVerifier._model = None

    def _load(self) -> bool:
        with self._lock:
            if ClaimVerifier._model is not None:
                return True
            try:
                from sentence_transformers import CrossEncoder

                model = CrossEncoder(self.model_name, device="cpu")
                ClaimVerifier._labels = [model.model.config.id2label[i].lower() for i in range(len(model.model.config.id2label))]
                ClaimVerifier._model = model
                logger.info(f"Claim check model '{self.model_name}' loaded.")
                return True
            except Exception as e:  # pragma: no cover - download / environment problems
                ClaimVerifier._failed = True  # do not retry on every request; word overlap is used instead
                logger.warning(f"Claim check model '{self.model_name}' unavailable ({type(e).__name__}); using word overlap only")
                return False

    def judge(self, claims: List[str], passages: List[str]) -> Optional[List[Judgement]]:
        """One Judgement per claim (against its closest passages), or None when the check is unavailable."""
        if not self.enabled or not claims or not passages:
            return None
        try:
            from app.services.huggingface_service import get_hf_service

            hf = get_hf_service()
            vectors = hf.generate_batch_embeddings(claims + passages)
            import numpy as np

            matrix = np.asarray(vectors, dtype=np.float32)
            matrix /= np.linalg.norm(matrix, axis=1, keepdims=True) + 1e-9
            sims = matrix[: len(claims)] @ matrix[len(claims):].T
        except Exception as e:
            logger.warning(f"Claim check embeddings failed ({type(e).__name__})")
            return None

        with model_memory.use("claim-check", self._unload, estimate_mb=900):
            if not self._load():
                return None
            pairs, owners = [], []
            for ci, claim in enumerate(claims):
                for pi in sims[ci].argsort()[::-1][:TOP_PASSAGES]:
                    pairs.append((passages[int(pi)], claim))
                    owners.append(ci)
            try:
                probs = ClaimVerifier._model.predict(pairs, apply_softmax=True, batch_size=16)
            except Exception as e:
                logger.warning(f"Claim check inference failed ({type(e).__name__})")
                return None

        labels = ClaimVerifier._labels or []
        ent_i, con_i = labels.index("entailment"), labels.index("contradiction")
        out: List[Judgement] = []
        for ci in range(len(claims)):
            rows = [(probs[k], pairs[k][0]) for k in range(len(pairs)) if owners[k] == ci]  # closest passage first
            best = max(rows, key=lambda r: float(r[0][ent_i]))
            out.append(Judgement(
                entailment=float(best[0][ent_i]),
                contradiction=float(rows[0][0][con_i]),
                similarity=float(sims[ci].max()),
                passage=best[1],
            ))
        return out


_verifier = ClaimVerifier()

# ------------------------------------------------------------------------------------------- abbreviations

_PAREN_ABBR = re.compile(r"\(([A-Z][A-Za-z0-9]{1,9})\)")


def abbreviations(texts: List[str]) -> Dict[str, str]:
    """Abbreviations the documents define, as {"BFS": "Breadth-first search"}.

    Recognises "Long form (ABBR)": the words just before the brackets whose initials (hyphenated parts count
    separately: Breadth-first -> B, F) spell the abbreviation.
    """
    found: Dict[str, str] = {}
    for text in texts:
        for match in _PAREN_ABBR.finditer(text or ""):
            abbr = match.group(1)
            letters = [ch.lower() for ch in abbr if ch.isalpha()]
            if len(letters) < 2 or abbr in found:
                continue
            words = re.findall(r"[A-Za-z][A-Za-z\-]*", text[: match.start()])[-(len(letters) + 3):]
            # Walk back word by word until the initials of the hyphen parts spell the abbreviation
            for start in range(len(words) - 1, -1, -1):
                parts = [p for w in words[start:] for p in w.split("-") if p]
                initials = [p[0].lower() for p in parts]
                if initials == letters:
                    found[abbr] = " ".join(words[start:])
                    break
                if len(initials) > len(letters):
                    break
    return found


def expand(claim: str, abbr: Dict[str, str]) -> str:
    """'BFS uses a queue' -> 'Breadth-first search (BFS) uses a queue' for abbreviations the documents define."""
    for short, long in abbr.items():
        if long.lower() not in claim.lower():
            claim = re.sub(rf"\b{re.escape(short)}\b", f"{long} ({short})", claim, count=1)
    return claim


# ------------------------------------------------------------------------------------------- hosted judge

JUDGE_PROMPT = (
    "You check whether claims are backed by source passages from course documents. Use ONLY the passages, not your own "
    "knowledge. For each claim answer:\n"
    "SUPPORTED - the passages state it or clearly imply it, including in other words or with equivalent numbers "
    "(\"one fifth\" = 20 percent);\nCONTRADICTED - the passages say something incompatible;\n"
    "NOT_FOUND - the passages do not say it (for example an added detail, example or name they never mention).\n"
    "Reply with JSON only: {{\"verdicts\": [...]}} with exactly {count} values, in claim order.\n\n"
    "PASSAGES:\n{passages}\n\nCLAIMS:\n{claims}"
)
VERDICTS = ("SUPPORTED", "CONTRADICTED", "NOT_FOUND")


def judge_models() -> List[str]:
    return [m.strip() for m in (get_settings().claim_judge_models or "").split(",") if m.strip()]


def judge_with_llm(claims: List[str], passages: List[str]) -> Optional[List[str]]:
    """One verdict per claim from a hosted model, or None (local provider, not configured, or the call failed)."""
    models = judge_models()
    if not claims or not passages or not models or provider_context.requested_provider() != "hosted" or not llm_chain.connection_configured():
        return None
    text, used = "", 0
    shown = []
    for p in passages:  # keep the prompt bounded; passages arrive most relevant first
        if used + len(p) > 12000:
            break
        shown.append(p)
        used += len(p)
    prompt = JUDGE_PROMPT.format(count=len(claims), passages="\n".join(f"[{i}] {p}" for i, p in enumerate(shown, 1)),
                                 claims="\n".join(f"{i}. {c}" for i, c in enumerate(claims, 1)))
    try:
        _, text = llm_chain.run_media(models, [{"type": "text", "text": prompt}], 400 + 20 * len(claims))
    except llm_chain.HostedCallError as e:
        logger.warning(f"Claim judge unavailable ({e.reason}); using the local check")
        return None
    match = re.search(r"\{.*\}", text, re.S)
    try:
        verdicts = json.loads(match.group(0)).get("verdicts") if match else None
    except ValueError:
        verdicts = None
    if not isinstance(verdicts, list) or len(verdicts) != len(claims):
        return None
    verdicts = [str(v).strip().upper().replace(" ", "_") for v in verdicts]
    return verdicts if all(v in VERDICTS for v in verdicts) else None


def get_claim_verifier() -> ClaimVerifier:
    return _verifier
