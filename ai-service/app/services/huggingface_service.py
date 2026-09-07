import os
import logging
from typing import List, Optional, Any
from app.config import get_settings

logger = logging.getLogger("facultylens.ai")


class HuggingFaceService:
    """
    Singleton service managing Hugging Face Sentence Transformers model loading,
    caching, and CPU-based embedding generation.
    """

    _instance: Optional["HuggingFaceService"] = None
    _model: Any = None
    _model_name: str = ""
    _is_loaded: bool = False
    _load_error: Optional[str] = None
    _embedding_dim: int = 384

    def __new__(cls) -> "HuggingFaceService":
        if cls._instance is None:
            cls._instance = super(HuggingFaceService, cls).__new__(cls)
            cls._instance._init_service()
        return cls._instance

    def _init_service(self) -> None:
        settings = get_settings()
        self._model_name = settings.hf_model_name
        if settings.hf_home:
            os.environ["HF_HOME"] = settings.hf_home
        if settings.hf_token:
            os.environ["HF_TOKEN"] = settings.hf_token

    def load_model(self) -> bool:
        """
        Load the SentenceTransformer model into memory if not already loaded.
        """
        if self._is_loaded and self._model is not None:
            return True

        settings = get_settings()
        self._model_name = settings.hf_model_name

        try:
            logger.info(f"Loading Hugging Face model: {self._model_name}...")
            # Import dynamically to allow service to start quickly and test easily
            from sentence_transformers import SentenceTransformer

            # Load model onto CPU
            self._model = SentenceTransformer(
                self._model_name,
                use_auth_token=settings.hf_token if settings.hf_token else None,
                device="cpu"
            )

            # Determine embedding dimension
            test_embedding = self._model.encode("test", convert_to_numpy=True)
            self._embedding_dim = int(len(test_embedding))

            self._is_loaded = True
            self._load_error = None
            logger.info(f"Successfully loaded model '{self._model_name}' (dim={self._embedding_dim}).")
            return True
        except Exception as e:
            self._is_loaded = False
            self._load_error = str(e)
            logger.error(f"Failed to load Hugging Face model '{self._model_name}': {e}", exc_info=True)
            return False

    @property
    def is_loaded(self) -> bool:
        return self._is_loaded

    @property
    def model_name(self) -> str:
        return self._model_name or get_settings().hf_model_name

    @property
    def embedding_dimension(self) -> int:
        return self._embedding_dim

    @property
    def load_error(self) -> Optional[str]:
        return self._load_error

    def generate_embedding(self, text: str) -> List[float]:
        """
        Generate embedding vector for a given text snippet.
        """
        if not self._is_loaded or self._model is None:
            success = self.load_model()
            if not success:
                raise RuntimeError(f"Hugging Face model '{self.model_name}' is not loaded: {self._load_error}")

        if not text or not text.strip():
            return [0.0] * self._embedding_dim

        # Inference on CPU
        vector = self._model.encode(text.strip(), convert_to_numpy=True)
        return vector.tolist()

    def generate_batch_embeddings(self, texts: List[str]) -> List[List[float]]:
        """
        Generate embeddings for a list of text snippets efficiently.
        """
        if not self._is_loaded or self._model is None:
            success = self.load_model()
            if not success:
                raise RuntimeError(f"Hugging Face model '{self.model_name}' is not loaded: {self._load_error}")

        if not texts:
            return []

        cleaned_texts = [t.strip() if t and t.strip() else " " for t in texts]
        vectors = self._model.encode(cleaned_texts, convert_to_numpy=True)
        return [v.tolist() for v in vectors]


def get_hf_service() -> HuggingFaceService:
    """Dependency injector / helper for accessing the HuggingFaceService instance."""
    return HuggingFaceService()

