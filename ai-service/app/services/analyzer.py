from typing import Dict, Any, Optional
from app.services.nlp_pipeline import NlpPipeline
from app.services.huggingface_service import HuggingFaceService


class AcademicTextAnalyzer:
    """
    High-level academic text analyzer invoking the NLP pipeline
    and structuring the output for downstream API consumers.
    """

    def __init__(
        self,
        nlp_pipeline: Optional[NlpPipeline] = None,
        hf_service: Optional[HuggingFaceService] = None,
    ):
        self.nlp_pipeline = nlp_pipeline or NlpPipeline(hf_service=hf_service)

    def analyze(self, text: str, document_type: str = "question_paper") -> Dict[str, Any]:
        """
        Run structured analysis on academic text.
        """
        result = self.nlp_pipeline.process(raw_text=text, document_type=document_type)

        return {
            "status": "success",
            "document_type": document_type,
            "analysis": {
                "character_count": result["character_count"],
                "word_count": result["word_count"],
                "sentence_count": result["sentence_count"],
                "paragraph_count": result["paragraph_count"],
                "questions_detected": result["questions_detected"],
                "questions": result["questions"],
                "keywords": result["keywords"],
                "embeddings_generated": result["embeddings_generated"],
                "embedding_dimension": result["embedding_dimension"],
                "model": result["model"],
            },
        }

