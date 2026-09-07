import re
from typing import Dict, Any


class CognitiveAnalyzer:
    """
    Classifies academic questions according to Revised Bloom's Taxonomy:
    REMEMBER, UNDERSTAND, APPLY, ANALYZE, EVALUATE, CREATE.
    """

    BLOOM_PATTERNS = {
        "CREATE": re.compile(
            r"\b(?:design|create|construct|compose|develop|formulate|synthesize|plan|"
            r"generate|architect|devise|propose|invent|integrate\s+a\s+new)\b",
            re.IGNORECASE,
        ),
        "EVALUATE": re.compile(
            r"\b(?:evaluate|critique|judge|justify|assess|defend|appraise|rate|validate|"
            r"recommend|prioritize|weigh|select\s+the\s+best)\b",
            re.IGNORECASE,
        ),
        "ANALYZE": re.compile(
            r"\b(?:analyze|compare|contrast|differentiate|distinguish|examine|investigate|"
            r"break\s*down|diagnose|categorize|identify\s+(?:anomalies|flaws|weaknesses|trade-offs))\b",
            re.IGNORECASE,
        ),
        "APPLY": re.compile(
            r"\b(?:apply|calculate|compute|solve|demonstrate|implement|execute|use|operate|"
            r"show\s+how|write\s+(?:a\s+)?(?:sql|query|program|code)|determine\s+the\s+output)\b",
            re.IGNORECASE,
        ),
        "UNDERSTAND": re.compile(
            r"\b(?:explain|describe|discuss|summarize|interpret|clarify|paraphrase|"
            r"illustrate|outline|why\s+does|how\s+does|elaborate)\b",
            re.IGNORECASE,
        ),
        "REMEMBER": re.compile(
            r"\b(?:define|state|list|name|mention|identify|recall|enumerate|what\s+is|"
            r"give\s+the\s+definition|label|match|quote)\b",
            re.IGNORECASE,
        ),
    }

    LEADING_VERB_PATTERNS = [
        ("REMEMBER", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:define|state|list|name|mention|identify|recall|enumerate|what\s+is|give\s+the\s+definition)\b", re.I)),
        ("UNDERSTAND", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:explain|describe|discuss|summarize|interpret|clarify|paraphrase|illustrate|outline|why\s+is|why\s+does|how\s+does)\b", re.I)),
        ("APPLY", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:apply|calculate|compute|solve|demonstrate|implement|execute|use|operate|show\s+how|write\s+(?:a\s+)?(?:sql|query|program|code))\b", re.I)),
        ("ANALYZE", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:analyze|compare|contrast|differentiate|distinguish|examine|investigate|break\s*down|diagnose|categorize)\b", re.I)),
        ("EVALUATE", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:evaluate|critique|judge|justify|assess|defend|appraise|rate|validate|recommend|prioritize)\b", re.I)),
        ("CREATE", re.compile(r"^\s*(?:(?:Q\d+[\.:\)]?|\d+[\.:\)])\s*)?(?:design|create|construct|compose|develop|formulate|synthesize|plan|generate|architect|devise|propose)\b", re.I)),
    ]

    @classmethod
    def analyze(cls, question_text: str) -> Dict[str, Any]:
        """
        Classify the cognitive level (Bloom's Taxonomy) for an academic question.
        """
        text = question_text.strip()
        if not text:
            return {
                "level": "UNDERSTAND",
                "method": "baseline",
            }

        # 1. First check if the question begins with a clear directive action verb
        for level, pattern in cls.LEADING_VERB_PATTERNS:
            if pattern.search(text):
                return {
                    "level": level,
                    "method": "baseline",
                }

        # 2. Otherwise scan in cognitive hierarchy (CREATE down to REMEMBER)
        for level in ["CREATE", "EVALUATE", "ANALYZE", "APPLY", "UNDERSTAND", "REMEMBER"]:
            pattern = cls.BLOOM_PATTERNS[level]
            if pattern.search(text):
                return {
                    "level": level,
                    "method": "baseline",
                }

        # 3. Fallback based on question phrasing
        if text.endswith("?"):
            words = text.lower().split()
            if words and words[0] in ("what", "which", "who", "when", "where"):
                return {"level": "REMEMBER", "method": "baseline"}

        return {
            "level": "UNDERSTAND",
            "method": "baseline",
        }
