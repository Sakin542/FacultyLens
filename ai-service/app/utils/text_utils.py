import re
from typing import List, Dict, Any


QUESTION_HEADER_PATTERN = re.compile(
    r"^\s*(?:(?:Question|Q)\s*(\d+)[\.:\)]?|(?:\((\d+)\)[\.:]?)|(?:\[(\d+)\][\.:]?)|(?:(\d+)[\.:\)]))\s*(.*)$",
    re.IGNORECASE,
)


def split_paragraphs(text: str) -> List[str]:
    """
    Split cleaned text into distinct non-empty paragraphs.
    """
    if not text or not text.strip():
        return []
    paragraphs = [p.strip() for p in re.split(r"\n\s*\n+", text) if p.strip()]
    return paragraphs


def split_sentences(text: str) -> List[str]:
    """
    Split text into distinct sentences while avoiding splits on common abbreviations and numbers.
    """
    if not text or not text.strip():
        return []

    # Replace newlines with spaces for sentence extraction if single newline
    normalized = re.sub(r"\s+", " ", text.strip())

    # Split on sentence-ending punctuation followed by space and capital letter or end of string
    raw_sentences = re.split(r"(?<=[.!?])\s+(?=[A-Z0-9\"'(\[])", normalized)
    sentences = [s.strip() for s in raw_sentences if s.strip()]
    return sentences if sentences else [normalized]


def extract_questions(text: str) -> List[Dict[str, Any]]:
    """
    Extract structured questions from academic text.
    Recognizes patterns:
    - 1. Explain... / 1) Explain... / (1) Explain...
    - Q1. Explain... / Q1: Explain... / Q1) Explain...
    - Question 1: Explain... / Question 1. Explain...
    """
    if not text or not text.strip():
        return []

    lines = [line.strip() for line in text.split("\n") if line.strip()]
    detected_questions: List[Dict[str, Any]] = []

    current_q_num: int = 0
    current_q_lines: List[str] = []

    for line in lines:
        match = QUESTION_HEADER_PATTERN.match(line)
        if match:
            # Save previous question if exists
            if current_q_num > 0 and current_q_lines:
                detected_questions.append({
                    "number": current_q_num,
                    "text": " ".join(current_q_lines).strip()
                })
                current_q_lines = []

            # Extract matched question number (from group 1, 2, 3, or 4)
            num_str = match.group(1) or match.group(2) or match.group(3) or match.group(4)
            current_q_num = int(num_str) if num_str and num_str.isdigit() else (len(detected_questions) + 1)

            # Remaining text on same line
            rest_of_line = match.group(5).strip()
            if rest_of_line:
                current_q_lines.append(rest_of_line)
        else:
            if current_q_num > 0:
                current_q_lines.append(line)

    # Flush last question
    if current_q_num > 0 and current_q_lines:
        detected_questions.append({
            "number": current_q_num,
            "text": " ".join(current_q_lines).strip()
        })

    return detected_questions


def extract_basic_keywords(text: str, top_k: int = 5) -> List[str]:
    """
    Extract prominent academic keywords by word frequency ignoring common stop words.
    """
    if not text:
        return []

    stop_words = {
        "the", "a", "an", "and", "or", "but", "in", "on", "at", "to", "for", "of", "with",
        "by", "from", "is", "are", "was", "were", "be", "been", "being", "have", "has", "had",
        "do", "does", "did", "can", "could", "should", "would", "will", "shall", "may", "might",
        "must", "this", "that", "these", "those", "what", "which", "who", "whom", "how", "why",
        "when", "where", "explain", "describe", "discuss", "compare", "define", "analyze", "list",
        "write", "differentiate", "illustrate", "briefly", "short", "notes", "following", "question"
    }

    words = re.findall(r"\b[a-zA-Z]{3,}\b", text.lower())
    freq: Dict[str, int] = {}
    for word in words:
        if word not in stop_words:
            freq[word] = freq.get(word, 0) + 1

    sorted_keywords = sorted(freq.items(), key=lambda item: item[1], reverse=True)
    return [word for word, _ in sorted_keywords[:top_k]]

