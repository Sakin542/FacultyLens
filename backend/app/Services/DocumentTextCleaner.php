<?php

namespace App\Services;

class DocumentTextCleaner
{
    /**
     * Adobe "Symbol" font glyphs that PDF parsers emit as Private Use Area code points (U+F000 + byte).
     * Slides typeset in PowerPoint/Word with the Symbol font (logic, probability, set theory, Greek letters)
     * otherwise lose every ∧ ¬ ∨ Σ Π symbol, which breaks retrieval and grounded answers over formulas.
     */
    protected const SYMBOL_FONT_MAP = [
        0x22 => '∀', 0x24 => '∃', 0x27 => '∋', 0x2A => '∗', 0x2D => '−', 0x40 => '≅',
        0x41 => 'Α', 0x42 => 'Β', 0x43 => 'Χ', 0x44 => 'Δ', 0x45 => 'Ε', 0x46 => 'Φ', 0x47 => 'Γ', 0x48 => 'Η',
        0x49 => 'Ι', 0x4A => 'ϑ', 0x4B => 'Κ', 0x4C => 'Λ', 0x4D => 'Μ', 0x4E => 'Ν', 0x4F => 'Ο', 0x50 => 'Π',
        0x51 => 'Θ', 0x52 => 'Ρ', 0x53 => 'Σ', 0x54 => 'Τ', 0x55 => 'Υ', 0x56 => 'ς', 0x57 => 'Ω', 0x58 => 'Ξ',
        0x59 => 'Ψ', 0x5A => 'Ζ', 0x5C => '∴', 0x5E => '⊥',
        0x61 => 'α', 0x62 => 'β', 0x63 => 'χ', 0x64 => 'δ', 0x65 => 'ε', 0x66 => 'φ', 0x67 => 'γ', 0x68 => 'η',
        0x69 => 'ι', 0x6A => 'ϕ', 0x6B => 'κ', 0x6C => 'λ', 0x6D => 'μ', 0x6E => 'ν', 0x6F => 'ο', 0x70 => 'π',
        0x71 => 'θ', 0x72 => 'ρ', 0x73 => 'σ', 0x74 => 'τ', 0x75 => 'υ', 0x76 => 'ϖ', 0x77 => 'ω', 0x78 => 'ξ',
        0x79 => 'ψ', 0x7A => 'ζ', 0x7E => '∼',
        0xA1 => 'ϒ', 0xA2 => '′', 0xA3 => '≤', 0xA4 => '⁄', 0xA5 => '∞', 0xA6 => 'ƒ', 0xAB => '↔', 0xAC => '←',
        0xAD => '↑', 0xAE => '→', 0xAF => '↓', 0xB0 => '°', 0xB1 => '±', 0xB2 => '″', 0xB3 => '≥', 0xB4 => '×',
        0xB5 => '∝', 0xB6 => '∂', 0xB7 => '•', 0xB8 => '÷', 0xB9 => '≠', 0xBA => '≡', 0xBB => '≈', 0xBC => '…',
        0xC0 => 'ℵ', 0xC4 => '⊗', 0xC5 => '⊕', 0xC6 => '∅', 0xC7 => '∩', 0xC8 => '∪', 0xC9 => '⊃', 0xCA => '⊇',
        0xCB => '⊄', 0xCC => '⊂', 0xCD => '⊆', 0xCE => '∈', 0xCF => '∉', 0xD0 => '∠', 0xD1 => '∇', 0xD5 => '∏',
        0xD6 => '√', 0xD7 => '⋅', 0xD8 => '¬', 0xD9 => '∧', 0xDA => '∨', 0xDB => '⇔', 0xDC => '⇐', 0xDD => '⇑',
        0xDE => '⇒', 0xDF => '⇓', 0xE0 => '◊', 0xE1 => '⟨', 0xE5 => '∑', 0xF1 => '⟩', 0xF2 => '∫',
    ];

    /**
     * Map Symbol-font Private Use Area glyphs (U+F020–U+F0FF) to real Unicode. Unmapped ASCII-range glyphs
     * (digits, parentheses, '=' …) become their ASCII character; anything else unknown is left untouched.
     */
    public function normalizeSymbolFontGlyphs(string $text): string
    {
        return preg_replace_callback('/[\x{F020}-\x{F0FF}]/u', function (array $m) {
            $byte = mb_ord($m[0], 'UTF-8') - 0xF000;
            if (isset(self::SYMBOL_FONT_MAP[$byte])) {
                return self::SYMBOL_FONT_MAP[$byte];
            }

            return $byte < 0x7F ? chr($byte) : $m[0];
        }, $text) ?? $text;
    }

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
        $text = str_replace(["\r\n", "\r"], "\n", $this->normalizeSymbolFontGlyphs($rawText));

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

