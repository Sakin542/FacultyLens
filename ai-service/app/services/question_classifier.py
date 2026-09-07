import re
from typing import Dict, Any


class QuestionClassifier:
    """
    Classifies academic questions into standardized pedagogical types:
    MCQ, TRUE_FALSE, PROBLEM_SOLVING, ANALYTICAL, CONCEPTUAL, SHORT_ANSWER, DESCRIPTIVE.
    """

    MCQ_PATTERN = re.compile(
        r"(?:(?:choose|select)\s+(?:the\s+)?(?:correct|best|appropriate)\s+(?:option|answer|choice))|"
        r"(?:\b[a-d]\s*[\.\)]\s+.*\b[b-d]\s*[\.\)])|"
        r"(?:\([a-d]\)\s+.*\([b-d]\))|"
        r"(?:which\s+of\s+the\s+following)",
        re.IGNORECASE,
    )

    TRUE_FALSE_PATTERN = re.compile(
        r"(?:true\s+or\s+false)|(?:\bT\s*/\s*F\b)|(?:\bstate\s+whether\s+true\s+or\s+false\b)",
        re.IGNORECASE,
    )

    PROBLEM_SOLVING_PATTERN = re.compile(
        r"\b(?:calculate|solve|compute|find\s+the\s+value|derive|evaluate\s+the\s+expression|"
        r"write\s+(?:a\s+)?(?:sql|query|code|program|algorithm|pseudocode)|determine\s+the\s+output)\b",
        re.IGNORECASE,
    )

    ANALYTICAL_PATTERN = re.compile(
        r"\b(?:analyze|compare|contrast|differentiate|distinguish|critique|examine|"
        r"justify|identify\s+(?:weaknesses|strengths|trade-offs|anomalies))\b",
        re.IGNORECASE,
    )

    CONCEPTUAL_PATTERN = re.compile(
        r"\b(?:define|what\s+is|state|list|name|mention|identify|enumerate|give\s+an\s+example)\b",
        re.IGNORECASE,
    )

    SHORT_ANSWER_PATTERN = re.compile(
        r"\b(?:briefly\s+explain|short\s+notes?|in\s+(?:few|brief)\s+words|outline)\b",
        re.IGNORECASE,
    )

    DESCRIPTIVE_PATTERN = re.compile(
        r"\b(?:explain|discuss|describe|elaborate|illustrate|detail|clarify)\b",
        re.IGNORECASE,
    )

    @classmethod
    def classify(cls, question_text: str) -> Dict[str, Any]:
        """
        Classify a question's pedagogical type with heuristic confidence scoring.
        """
        text = question_text.strip()
        if not text:
            return {
                "question_type": "DESCRIPTIVE",
                "confidence": 0.50,
            }

        # 1. Check True/False
        if cls.TRUE_FALSE_PATTERN.search(text):
            return {"question_type": "TRUE_FALSE", "confidence": 0.95}

        # 2. Check MCQ
        if cls.MCQ_PATTERN.search(text):
            return {"question_type": "MCQ", "confidence": 0.92}

        # 3. Check Problem Solving / Code / Math
        if cls.PROBLEM_SOLVING_PATTERN.search(text):
            return {"question_type": "PROBLEM_SOLVING", "confidence": 0.88}

        # 4. Check Analytical
        if cls.ANALYTICAL_PATTERN.search(text):
            return {"question_type": "ANALYTICAL", "confidence": 0.87}

        # 5. Check Short Answer indicators
        if cls.SHORT_ANSWER_PATTERN.search(text):
            return {"question_type": "SHORT_ANSWER", "confidence": 0.84}

        # 6. Check Conceptual / Definitional
        if cls.CONCEPTUAL_PATTERN.search(text):
            return {"question_type": "CONCEPTUAL", "confidence": 0.85}

        # 7. Check Descriptive / Explanatory
        if cls.DESCRIPTIVE_PATTERN.search(text):
            return {"question_type": "DESCRIPTIVE", "confidence": 0.86}

        # Fallback based on length
        word_count = len(text.split())
        if word_count < 12:
            return {"question_type": "SHORT_ANSWER", "confidence": 0.65}

        return {"question_type": "DESCRIPTIVE", "confidence": 0.70}

