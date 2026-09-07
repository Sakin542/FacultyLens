import re


class TextCleaner:
    """
    Cleans and normalizes raw extracted academic text while strictly preserving
    question numbers, punctuation, academic terminology, mathematical notation,
    and paragraph boundaries.
    """

    @classmethod
    def clean(cls, raw_text: str) -> str:
        if not raw_text or not raw_text.strip():
            return ""

        # 1. Normalize line endings to \n
        text = raw_text.replace("\r\n", "\n").replace("\r", "\n")

        # 2. Remove null bytes and non-printable control characters, but preserve tabs, newlines, and valid Unicode
        text = re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]", "", text)

        # 3. Replace non-breaking spaces with standard spaces
        text = text.replace("\u00a0", " ").replace("&nbsp;", " ")

        # 4. Process line-by-line to preserve structure and clean internal whitespace
        lines = text.split("\n")
        cleaned_lines = []

        for line in lines:
            trimmed = line.rstrip(" \t")
            if not trimmed.strip():
                cleaned_lines.append("")
                continue

            # Collapse consecutive spaces/tabs within a single line
            normalized_line = re.sub(r"[ \t]{2,}", " ", trimmed.strip())
            cleaned_lines.append(normalized_line)

        # 5. Join lines back
        joined = "\n".join(cleaned_lines)

        # 6. Normalize multiple consecutive blank lines (max 2 consecutive newlines)
        normalized = re.sub(r"\n{3,}", "\n\n", joined)

        # 7. Final trim
        return normalized.strip()

