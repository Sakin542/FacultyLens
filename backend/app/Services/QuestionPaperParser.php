<?php

namespace App\Services;

/**
 * Splits the text of a question paper into numbered questions with their marks, for the faculty member to review
 * before anything is saved.
 *
 * - A question starts at a line numbered "1." / "1)" / "Q1" / "Q1." / "Question 1:"; numbers must run 1, 2, 3 …,
 *   so a "1)" sub-part inside question 1 or a stray "10 marks" line does not start a new question.
 * - Lines before the first question (paper title, instructions) are ignored; sub-parts (a), (b), i. stay with their
 *   question.
 * - Marks come from the end of a question: "[10]", "(10 marks)", "[5 pts]", "10 marks".
 */
class QuestionPaperParser
{
    public const MAX_QUESTIONS = 100;

    private const START = '/^\s*(?:(?:Q(?:uestion)?\s*\.?\s*)(\d{1,3})\s*[.):\-]?|(\d{1,3})\s*[.)])\s+(.*)$/iu';
    private const MARKS = '/\s*(?:[\[(]\s*(\d{1,3}(?:\.\d{1,2})?)\s*(?:marks?|mks?|pts?|points?)?\s*[\])]|(\d{1,3}(?:\.\d{1,2})?)\s*(?:marks?|mks?))\s*\.?$/iu';

    /**
     * @return array{questions: list<array{number: int, text: string, marks: ?float}>, warnings: list<string>}
     */
    public function parse(string $text, ?float $totalMarks = null): array
    {
        $lines = preg_split('/\R/u', str_replace(["\t", "\u{00A0}"], ' ', $text)) ?: [];
        $blocks = [];
        $current = null;
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match(self::START, $line, $m)) {
                $number = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                $expected = count($blocks) + ($current ? 1 : 0) + 1;
                if ($number === $expected) {
                    if ($current) {
                        $blocks[] = $current;
                    }
                    $current = ['number' => $number, 'lines' => [trim($m[3])]];
                    continue;
                }
            }
            if ($current && trim($line) !== '') {
                $current['lines'][] = trim($line);
            }
        }
        if ($current) {
            $blocks[] = $current;
        }

        $questions = [];
        foreach (array_slice($blocks, 0, self::MAX_QUESTIONS) as $b) {
            $lines = array_values(array_filter(array_map(fn ($l) => preg_replace('/[ ]{2,}/', ' ', $l), $b['lines']), fn ($l) => $l !== ''));
            // Marks usually close the question, but a note may follow them, so take the last line that ends with marks
            $marks = null;
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (preg_match(self::MARKS, $lines[$i], $mm)) {
                    $marks = (float) ($mm[1] !== '' ? $mm[1] : $mm[2]);
                    $lines[$i] = rtrim(substr($lines[$i], 0, -strlen($mm[0])));
                    break;
                }
            }
            $body = trim(implode("\n", array_filter($lines, fn ($l) => $l !== '')));
            if ($body === '') {
                continue;
            }
            $questions[] = ['number' => $b['number'], 'text' => mb_substr($body, 0, 10000), 'marks' => $marks];
        }

        $warnings = [];
        if (!$questions) {
            $warnings[] = 'No numbered questions were found. Questions should start with "1.", "1)", "Q1" or "Question 1".';
        }
        if (count($blocks) > self::MAX_QUESTIONS) {
            $warnings[] = 'Only the first ' . self::MAX_QUESTIONS . ' questions were read.';
        }
        $missing = array_values(array_map(fn ($q) => $q['number'], array_filter($questions, fn ($q) => $q['marks'] === null)));
        if ($questions && $missing) {
            $warnings[] = 'No marks found for question' . (count($missing) > 1 ? 's ' : ' ') . implode(', ', $missing) . '; set them before saving.';
        }
        $sum = array_sum(array_map(fn ($q) => (float) $q['marks'], $questions));
        if ($totalMarks !== null && $questions && !$missing && abs($sum - $totalMarks) > 0.005) {
            $warnings[] = sprintf('The questions add up to %s marks; the assessment total is %s.', $this->fmt($sum), $this->fmt($totalMarks));
        }

        return ['questions' => $questions, 'warnings' => $warnings];
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
