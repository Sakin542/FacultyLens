"""Shared threshold banding (STEP 42, BUG-006).

Every classification is made on the score exactly as it is reported to the caller
(rounded to ``SCORE_PRECISION`` decimals) so a displayed 0.85 is never labelled
"HIGHLY_SIMILAR" and a displayed 0.70 is never labelled "WEAK".
"""

SCORE_PRECISION = 4


def reported_score(score: float) -> float:
    return round(float(score), SCORE_PRECISION)


def classify_similarity(score: float, duplicate: float, high: float, moderate: float) -> str:
    s = reported_score(score)
    if s >= duplicate:
        return "POTENTIAL_DUPLICATE"
    if s >= high:
        return "HIGHLY_SIMILAR"
    if s >= moderate:
        return "SOMEWHAT_SIMILAR"
    return "NOT_SIMILAR"


def classify_alignment(score: float, strong: float, weak: float) -> str:
    s = reported_score(score)
    if s >= strong:
        return "STRONG"
    if s >= weak:
        return "WEAK"
    return "NOT_ALIGNED"
