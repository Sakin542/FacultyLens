"""Embeddings for the answer similarity / plagiarism check.

Uses ANSWER_SIMILARITY_MODEL (BAAI/bge-m3 by default): multilingual (English and Bangla answers compare correctly,
including across the two languages) and stronger at paraphrase detection than MiniLM. It is a separate model from
hf_model_name on purpose: retrieval, grading and outcome matching keep MiniLM and the thresholds tuned for it.

bge-m3 is ~2.3 GB and loads on first use. If it cannot be loaded, the shared MiniLM service is used instead and the
response names the model that produced the vectors. The similarity scorer compares each pair against the median pair
for the same question, so its scores stay meaningful whichever model embedded the answers.
"""

import logging
import threading
from typing import Any, Dict, List, Optional, Tuple

from app.config import get_settings
from app.services import model_memory
from app.services.huggingface_service import EmbeddingCache, get_hf_service

logger = logging.getLogger("facultylens.ai")


class AnswerEmbedder:
    _lock = threading.Lock()
    _model: Any = None
    _loaded_name: Optional[str] = None
    _load_error: Optional[str] = None
    _cache = EmbeddingCache(5000)

    @property
    def model_name(self) -> str:
        return (get_settings().answer_similarity_model or "").strip()

    @property
    def loaded(self) -> bool:
        return AnswerEmbedder._model is not None and AnswerEmbedder._loaded_name == self.model_name

    @property
    def load_error(self) -> Optional[str]:
        return AnswerEmbedder._load_error

    def _load(self) -> bool:
        name = self.model_name
        if not name:
            return False
        with self._lock:
            if self.loaded:
                return True
            try:
                from sentence_transformers import SentenceTransformer

                model = SentenceTransformer(name, device="cpu", token=get_settings().hf_token or None)
                model.max_seq_length = 512  # answers are short; bounds memory and time on long ones
                AnswerEmbedder._model, AnswerEmbedder._loaded_name, AnswerEmbedder._load_error = model, name, None
                logger.info(f"Answer similarity model '{name}' loaded.")
                return True
            except Exception as e:  # pragma: no cover - download / memory problems are environment dependent
                AnswerEmbedder._load_error = type(e).__name__
                logger.warning(f"Answer similarity model '{name}' unavailable ({type(e).__name__}); using the shared embedding model")
                return False

    @staticmethod
    def _unload() -> None:
        AnswerEmbedder._model = AnswerEmbedder._loaded_name = None

    def embed(self, texts: List[str]) -> Tuple[List[List[float]], str]:
        """Return (unit-length vectors, model that produced them)."""
        with model_memory.use("answer-embeddings", self._unload, estimate_mb=2300):
            return self._embed(texts)

    def _embed(self, texts: List[str]) -> Tuple[List[List[float]], str]:
        cleaned = [t.strip() if t and t.strip() else " " for t in texts]
        if not self._load():
            hf = get_hf_service()
            return hf.generate_batch_embeddings(cleaned), hf.model_name

        name = self.model_name
        keys = [EmbeddingCache.key(name, t) for t in cleaned]
        found: Dict[str, Any] = self._cache.get_many(keys)
        missing: Dict[str, int] = {}
        for i, k in enumerate(keys):
            if k not in found and k not in missing:
                missing[k] = i
        if missing:
            vectors = AnswerEmbedder._model.encode([cleaned[i] for i in missing.values()], batch_size=16,
                                                   normalize_embeddings=True, convert_to_numpy=True)
            fresh = {k: v.astype("float32", copy=False) for k, v in zip(missing.keys(), vectors)}
            self._cache.put_many(fresh)
            found.update(fresh)
        return [found[k].tolist() for k in keys], name


_embedder = AnswerEmbedder()


def get_answer_embedder() -> AnswerEmbedder:
    return _embedder
