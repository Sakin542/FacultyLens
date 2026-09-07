<?php

namespace App\Services;

class DocumentTextCleaner
{
    /**
     * Clean and normalize raw extracted text from academic documents.
     * Preserves question numbering, structure, mathematical notations, and academic terminology.
     */
    public function clean(string $rawText): string
    {
        if (trim($rawText) === '') {
            return '';
        }

        // 1. Normalize all line endings to LF (\n)
        $text = str_replace(["\r\n", "\r"], "\n", $rawText);

        // 2. Remove null bytes and non-printable control characters, but preserve tabs, newlines, and valid UTF-8
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 3. Replace non-breaking spaces with standard space
        $text = str_replace(["\xC2\xA0", '&nbsp;'], ' ', $text);

        // 4. Split into lines to process each line intelligently
        $lines = explode("\n", $text);
        $cleanedLines = [];

        foreach ($lines as $line) {
            // Trim trailing spaces/tabs
            $line = rtrim($line, " \t");

            // If line is empty or purely whitespace, keep it as empty line
            if (trim($line) === '') {
                $cleanedLines[] = '';
                continue;
            }

            // Check if line begins with question markers or list patterns:
            // e.g. "1.", "1)", "1.1", "(a)", "a.", "a)", "(i)", "i.", "Q1:", "Question 1:", "Part A:", "Section B:", "[1]", etc.
            $isQuestionHeaderOrListItem = preg_match(
                '/^\s*(?:(?:Q(?:uestion)?\s*\d+[\.:\)]?)|(?:\d+(?:\.\d+)*[\.:\)])|(?:\([a-zA-Z0-9]+\))|(?:[a-zA-Z0-9]+[\.:\)])|(?:Part\s+[A-Z0-9]+[\.:]?)|(?:Section\s+[A-Z0-9]+[\.:]?)|(?:CLO\s*\d+[\.:]?)|(?:PLO\s*\d+[\.:]?)|(?:CO\s*\d+[\.:]?)|(?:\[\d+\]))\s*/i',
                $line
            );

            // Preserve leading indentation for sub-questions or bullet structures if present
            if ($isQuestionHeaderOrListItem) {
                // Keep minimal leading indent if nested (max 4 spaces)
                preg_match('/^(\s*)/', $line, $leadingSpaces);
                $indent = strlen($leadingSpaces[1] ?? '') > 0 ? '  ' : '';
                $trimmed = trim($line);
                // Collapse multiple spaces inside the line to single space
                $trimmed = preg_replace('/[ \t]{2,}/', ' ', $trimmed);
                $cleanedLines[] = $indent . $trimmed;
            } else {
                // For standard body lines, trim leading/trailing whitespace and collapse internal excessive spaces
                $trimmed = trim($line);
                $trimmed = preg_replace('/[ \t]{2,}/', ' ', $trimmed);
                $cleanedLines[] = $trimmed;
            }
        }

        // 5. Join lines back
        $text = implode("\n", $cleanedLines);

        // 6. Normalize multiple consecutive blank lines to maximum of 2 blank lines (1 empty line separating paragraphs)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // 7. Final trim of the entire document
        return trim($text);
    }
}

