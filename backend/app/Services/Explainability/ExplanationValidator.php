<?php

namespace App\Services\Explainability;

/**
 * Contradiction check for free-text explanations against the structured result they describe (STEP 45 §42).
 * Mirrors ai-service/app/services/explainability.py::validate_explanation. Invalid text is never displayed;
 * callers fall back to a deterministic explanation.
 */
class ExplanationValidator
{
    protected const FORBIDDEN = [
        'exact duplicate', '100% certain', '100% accurate', 'the model thought', 'the model thinks', 'neural network knows',
        'the ai understands', 'the ai knows', 'system prompt', 'chain of thought', 'chain-of-thought', 'api key', 'hf_token',
        'students will fail', 'you must',
    ];

    protected const VOCAB = [
        'cognitive_level' => ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'],
        'difficulty' => ['EASY', 'MEDIUM', 'HARD'],
        'similarity_status' => ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR', 'SOMEWHAT_SIMILAR', 'NOT_SIMILAR'],
        'alignment' => ['STRONG', 'WEAK', 'NOT_ALIGNED'],
    ];

    /**
     * @param array{scores?:array<string,float|null>,labels?:array<string,?string>,allowed_numbers?:array<float>,codes?:array<string>} $facts
     * @return array{valid:bool,violations:array<string>}
     */
    public function validate(?string $text, array $facts = []): array
    {
        $violations = [];
        if ($text === null || trim($text) === '') {
            return ['valid' => false, 'violations' => ['Explanation is empty.']];
        }
        $lower = mb_strtolower($text);

        foreach (self::FORBIDDEN as $phrase) {
            if (str_contains($lower, $phrase)) {
                $violations[] = "Contains forbidden wording: '{$phrase}'.";
            }
        }
        if (preg_match('/ignore (all|any|the)? ?(previous|prior|above) instructions|reveal (the )?(system|hidden) prompt|<<<|>>>/i', $text)) {
            $violations[] = 'Contains instruction-like or delimiter content.';
        }

        $allowed = array_values(array_filter(array_map(fn ($v) => $v === null ? null : (float) $v, array_merge(array_values($facts['scores'] ?? []), $facts['allowed_numbers'] ?? [])), fn ($v) => $v !== null));
        if ($allowed !== []) {
            preg_match_all('/(?<![\w.])(\d+(?:\.\d+)?)/', $text, $m);
            foreach ($m[1] as $raw) {
                $value = (float) $raw;
                if (!$this->approximatelyIn($value, $allowed)) {
                    $violations[] = "Mentions number {$raw} which does not match any known score or value.";
                }
            }
        }

        foreach (self::VOCAB as $key => $vocab) {
            $actual = isset($facts['labels'][$key]) ? $this->normalize($facts['labels'][$key]) : null;
            if (!$actual) {
                continue;
            }
            $actualHuman = strtolower(str_replace('_', ' ', $actual));
            $actualMentioned = preg_match('/\b' . preg_quote($actualHuman, '/') . '\b/', $lower) === 1;
            foreach ($vocab as $candidate) {
                if ($candidate === $actual) {
                    continue;
                }
                $human = strtolower(str_replace('_', ' ', $candidate));
                if (preg_match('/\b' . preg_quote($human, '/') . '\b/', $lower) !== 1) {
                    continue;
                }
                $asserted = preg_match('/(?:is|as|level[:\s]+|difficulty[:\s]+|classified\s+as|rated\s+as|status[:\s]+)\s*(?:an?\s+)?' . preg_quote($human, '/') . '\b/', $lower) === 1;
                if ($asserted || !$actualMentioned) {
                    $violations[] = "States {$key} '{$candidate}' but the actual value is '{$actual}'.";
                }
            }
        }

        if (!empty($facts['codes'])) {
            $codes = array_map(fn ($c) => strtoupper(preg_replace('/[\s-]/', '', (string) $c)), $facts['codes']);
            preg_match_all('/\b((?:LO|CO|PO|CLO|PLO)\s?-?\d+)\b/i', $text, $m);
            foreach ($m[1] as $raw) {
                if (!in_array(strtoupper(preg_replace('/[\s-]/', '', $raw)), $codes, true)) {
                    $violations[] = "References outcome code {$raw} which is not part of this result.";
                }
            }
        }

        return ['valid' => $violations === [], 'violations' => $violations];
    }

    /** Return the text when valid, otherwise the deterministic fallback. */
    public function validatedOrFallback(?string $text, array $facts, string $fallback): array
    {
        $verdict = $this->validate($text, $facts);
        return [
            'text' => $verdict['valid'] ? $text : $fallback,
            'validated' => $verdict['valid'],
            'fallback_used' => !$verdict['valid'],
            'violations' => $verdict['violations'],
        ];
    }

    protected function approximatelyIn(float $value, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if (abs($value - $a) <= 0.5 + 1e-9) {
                return true;
            }
            if ($a <= 1.0 && abs($value - $a * 100) <= 0.5 + 1e-9) {
                return true;
            }
        }
        return false;
    }

    protected function normalize(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        $n = strtoupper(str_replace([' ', '-'], '_', trim($label)));
        return $n === '' ? null : $n;
    }
}
