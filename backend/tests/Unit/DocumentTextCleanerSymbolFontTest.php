<?php

namespace Tests\Unit;

use App\Services\DocumentTextCleaner;
use PHPUnit\Framework\TestCase;

/**
 * PDFs typeset with the Adobe Symbol font (logic / probability slides) come out of the parser as
 * Private Use Area glyphs (U+F0xx). They must be mapped to real Unicode before storage and indexing.
 */
class DocumentTextCleanerSymbolFontTest extends TestCase
{
    private function pua(int $byte): string
    {
        return mb_chr(0xF000 + $byte, 'UTF-8');
    }

    public function test_logic_and_probability_symbols_are_mapped(): void
    {
        $raw = 'P(a '.$this->pua(0xD9).' '.$this->pua(0xD8).'j '.$this->pua(0xDA).' m) '.$this->pua(0xBB).' 0.115 '
            .$this->pua(0x53).' '.$this->pua(0x50).' '.$this->pua(0xE1).'37%, 63%'.$this->pua(0xF1);

        $clean = (new DocumentTextCleaner())->clean($raw);

        $this->assertSame('P(a ∧ ¬j ∨ m) ≈ 0.115 Σ Π ⟨37%, 63%⟩', $clean);
        $this->assertDoesNotMatchRegularExpression('/[\x{E000}-\x{F8FF}]/u', $clean);
    }

    public function test_ascii_range_glyphs_become_ascii_and_greek_letters_map(): void
    {
        $cleaner = new DocumentTextCleaner();

        $this->assertSame('(1)=', $cleaner->normalizeSymbolFontGlyphs($this->pua(0x28).$this->pua(0x31).$this->pua(0x29).$this->pua(0x3D)));
        $this->assertSame('αβπ', $cleaner->normalizeSymbolFontGlyphs($this->pua(0x61).$this->pua(0x62).$this->pua(0x70)));
    }

    public function test_text_without_private_use_glyphs_is_unchanged(): void
    {
        $text = 'Bayes’ rule: P(b | a) = P(a | b) * P(b) / P(a) — ✓ ➢';

        $this->assertSame($text, (new DocumentTextCleaner())->normalizeSymbolFontGlyphs($text));
    }
}
