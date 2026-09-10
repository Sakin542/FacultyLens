<?php

namespace App\Services;

/**
 * STEP 32: Splits academic document text into overlapping, retrieval-sized chunks.
 *
 * Chunking is word-based (deterministic, no tokenizer dependency). Sizes are configurable via
 * config/academic_chat.php. Page numbers are attached only when the caller supplies per-page text
 * (PDFs); section titles come from a conservative heading heuristic and may be null.
 */
class DocumentChunker
{
    public function __construct(
        protected int $chunkSizeWords = 700,
        protected int $overlapWords = 120,
        protected int $maxChunks = 2000,
    ) {
        $this->chunkSizeWords = max(5, $chunkSizeWords);
        $this->overlapWords = max(0, min($overlapWords, (int) floor($this->chunkSizeWords / 2)));
        $this->maxChunks = max(1, $maxChunks);
    }

    /**
     * @param string $text Full document text (used when no page map is given).
     * @param array<int, string> $pages Optional 1-based page number => page text.
     * @return array<int, array{chunk_index:int, content:string, content_hash:string, page_number:?int, section_title:?string, word_count:int}>
     */
    public function chunk(string $text, array $pages = []): array
    {
        $units = $pages !== [] ? $this->pagesToUnits($pages) : [[null, $text]];

        // Build a flat word stream carrying page + section metadata per word.
        $words = [];
        foreach ($units as [$pageNumber, $unitText]) {
            $section = null;
            foreach (preg_split('/\R+/u', $unitText) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if ($this->looksLikeHeading($line)) {
                    $section = mb_substr($line, 0, 255);
                }
                foreach (preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $words[] = [$word, $pageNumber, $section];
                }
            }
        }

        $total = count($words);
        if ($total === 0) {
            return [];
        }

        $chunks = [];
        $step = max(1, $this->chunkSizeWords - $this->overlapWords);
        for ($start = 0, $index = 0; $start < $total && $index < $this->maxChunks; $start += $step, $index++) {
            $slice = array_slice($words, $start, $this->chunkSizeWords);
            $content = implode(' ', array_column($slice, 0));
            $chunks[] = [
                'chunk_index' => $index,
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'page_number' => $slice[0][1],
                'section_title' => $slice[0][2],
                'word_count' => count($slice),
            ];
            if ($start + $this->chunkSizeWords >= $total) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Stable hash of the text that drives indexing; unchanged hash => no re-embedding.
     */
    public function contentHash(string $text, string $model, string $version): string
    {
        return hash('sha256', $model . '|' . $version . '|' . $this->chunkSizeWords . '|' . $this->overlapWords . '|' . $text);
    }

    /**
     * @param array<int, string> $pages
     * @return array<int, array{0:?int,1:string}>
     */
    protected function pagesToUnits(array $pages): array
    {
        $units = [];
        ksort($pages);
        foreach ($pages as $number => $pageText) {
            if (trim((string) $pageText) === '') {
                continue;
            }
            $units[] = [(int) $number, (string) $pageText];
        }

        return $units;
    }

    /**
     * Conservative heading heuristic: short line, no terminal punctuation, numbered or Title/UPPER case.
     */
    protected function looksLikeHeading(string $line): bool
    {
        $length = mb_strlen($line);
        if ($length < 3 || $length > 90) {
            return false;
        }
        if (preg_match('/[.!?;:,]$/u', $line)) {
            return false;
        }
        if (str_word_count($line) > 12) {
            return false;
        }
        if (preg_match('/^(chapter|unit|module|section|lecture|week|topic|part)\b/iu', $line)) {
            return true;
        }
        if (preg_match('/^\d+(\.\d+)*\.?\s+\S/u', $line)) {
            return true;
        }
        if (mb_strtoupper($line) === $line && preg_match('/[A-Z]{3,}/u', $line)) {
            return true;
        }

        $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $capitalised = 0;
        foreach ($words as $word) {
            if (preg_match('/^\p{Lu}/u', $word)) {
                $capitalised++;
            }
        }

        return count($words) >= 2 && $capitalised === count($words);
    }
}
