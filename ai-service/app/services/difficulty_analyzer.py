import re
from typing import Dict, Any


class DifficultyAnalyzer:
    """
    Analyzes question difficulty level using a baseline heuristic model combining
    syntactic complexity, action verbs, multi-clause structures, and cognitive depth cues.
    """

    HARD_CUES = re.compile(
        r"\b(?:design|synthesize|optimize|architect|justify|derive|prove|critique|"
        r"evaluate\s+the\s+trade-offs|formulate|construct|investigate|integrate|"
        r"implement\s+a\s+complete|scenario|complex|distributed|fault-tolerant)\b",
        re.IGNORECASE,
    )

    MEDIUM_CUES = re.compile(
        r"\b(?:explain|compare|contrast|differentiate|distinguish|apply|demonstrate|"
        r"illustrate|calculate|compute|solve|how\s+does|why\s+is|discuss)\b",
        re.IGNORECASE,
    )

    EASY_CUES = re.compile(
        r"\b(?:define|what\s+is|state|list|name|mention|identify|enumerate|"
        r"which\s+of\s+the|give\s+the\s+definition|true\s+or\s+false)\b",
        re.IGNORECASE,
    )

    @classmethod
    def analyze(cls, question_text: str) -> Dict[str, Any]:
        """
        Analyze and determine the baseline difficulty of an academic question.
        """
        text = question_text.strip()
        if not text:
            return {
                "level": "MEDIUM",
                "method": "baseline",
            }

        words = text.split()
        word_count = len(words)

        hard_matches = len(cls.HARD_CUES.findall(text))
        medium_matches = len(cls.MEDIUM_CUES.findall(text))
        easy_matches = len(cls.EASY_CUES.findall(text))

        # Check for multiple question sub-clauses or scenario length
        has_subclauses = bool(re.search(r"[;\n]|\band\s+also\b|\bmoreover\b|\bfurthermore\b", text, re.I))

        # Scoring heuristic
        score = 0
        if hard_matches > 0:
            score += hard_matches * 3
        if medium_matches > 0:
            score += medium_matches * 2
        if easy_matches > 0:
            score -= easy_matches * 1.5

        if word_count > 30:
            score += 2
        elif word_count > 18:
            score += 1
        elif word_count < 10:
            score -= 1.5

        if has_subclauses and word_count > 20:
            score += 1.5

        # Classify by score threshold
        if score >= 3.0:
            level = "HARD"
        elif score <= -0.5:
            level = "EASY"
        else:
            level = "MEDIUM"

        return {
            "level": level,
            "method": "baseline",
        }

