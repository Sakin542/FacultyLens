import logging
from typing import Dict, Any, Optional
from app.services.text_cleaner import TextCleaner
from app.services.huggingface_service import HuggingFaceService, get_hf_service
from app.utils.text_utils import (
    split_paragraphs,
    split_sentences,
    extract_questions,
    extract_basic_keywords,
)

logger = logging.getLogger("facultylens.ai")


class NlpPipeline:
    """
    Modular NLP Processing Pipeline for academic documents.
    Sequentially applies cleaning, segmentation, question detection,
    and Hugging Face model embedding generation.
    """

    def __init__(self, hf_service: Optional[HuggingFaceService] = None):
        self.hf_service = hf_service or get_hf_service()

    def process(self, raw_text: str, document_type: str = "general") -> Dict[str, Any]:
        """
        Execute full NLP processing on raw academic text.
        """
        # 1. Text Cleaning
        cleaned_text = TextCleaner.clean(raw_text)

        # 2. Paragraph Detection
        paragraphs = split_paragraphs(cleaned_text)

        # 3. Sentence Detection
        sentences = split_sentences(cleaned_text)

        # 4. Question Detection
        questions = extract_questions(cleaned_text)

        # 5. Basic Statistics
        char_count = len(cleaned_text)
        word_count = len(cleaned_text.split()) if cleaned_text else 0
        sentence_count = len(sentences)
        paragraph_count = len(paragraphs)
        questions_detected = len(questions)

        # 6. Basic Keywords
        keywords = extract_basic_keywords(cleaned_text, top_k=5)

        # 7. Hugging Face Embedding Generation (verify generation & dimension)
        embeddings_generated = False
        embedding_dim = self.hf_service.embedding_dimension

        try:
            # Generate embedding for the document or sample to verify pipeline integration
            if cleaned_text:
                _ = self.hf_service.generate_embedding(cleaned_text[:512])
                embeddings_generated = True
        except Exception as e:
            logger.warning(f"Embedding generation skipped or failed during pipeline: {e}")
            embeddings_generated = False

        return {
            "cleaned_text": cleaned_text,
            "character_count": char_count,
            "word_count": word_count,
            "sentence_count": sentence_count,
            "paragraph_count": paragraph_count,
            "paragraphs": paragraphs,
            "sentences": sentences,
            "questions_detected": questions_detected,
            "questions": questions,
            "keywords": keywords,
            "embeddings_generated": embeddings_generated,
            "embedding_dimension": embedding_dim,
            "model": self.hf_service.model_name,
        }

