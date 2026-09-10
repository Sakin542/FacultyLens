<?php

namespace App\Services\AiEvaluation;

/**
 * STEP 35: multi-class classification metrics computed from expected/predicted label lists.
 * Pure functions — no I/O — so they can be unit-tested against hand-verified values.
 */
class ClassificationEvaluator
{
    /**
     * @param string[] $expected
     * @param string[] $predicted
     * @param string[]|null $labels fixed label order (defaults to sorted union)
     * @return array{accuracy:float, macro_f1:float, weighted_f1:float, macro_precision:float, macro_recall:float, per_class:array, confusion_matrix:array{labels:string[], matrix:int[][]}, support:int}
     */
    public function metrics(array $expected, array $predicted, ?array $labels = null): array
    {
        $n = min(count($expected), count($predicted));
        $expected = array_slice(array_values($expected), 0, $n);
        $predicted = array_slice(array_values($predicted), 0, $n);
        $labels = $labels ? array_values($labels) : array_values(array_unique(array_merge($expected, $predicted)));
        if (!$labels) {
            sort($labels);
        }
        foreach (array_unique(array_merge($expected, $predicted)) as $l) {
            if (!in_array($l, $labels, true)) {
                $labels[] = $l;
            }
        }
        $idx = array_flip($labels);
        $k = count($labels);
        $matrix = array_fill(0, $k, array_fill(0, $k, 0));
        $correct = 0;
        for ($i = 0; $i < $n; $i++) {
            $matrix[$idx[$expected[$i]]][$idx[$predicted[$i]]]++;
            if ($expected[$i] === $predicted[$i]) {
                $correct++;
            }
        }

        $perClass = [];
        $macroP = $macroR = $macroF = $weightedF = 0.0;
        $occurring = 0; // macro averages ignore configured labels that never occur in expected or predicted
        foreach ($labels as $li => $label) {
            $tp = $matrix[$li][$li];
            $fp = array_sum(array_column($matrix, $li)) - $tp;
            $fn = array_sum($matrix[$li]) - $tp;
            $support = $tp + $fn;
            $p = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
            $r = $support > 0 ? $tp / $support : 0.0;
            $f = ($p + $r) > 0 ? 2 * $p * $r / ($p + $r) : 0.0;
            $perClass[] = ['label' => $label, 'precision' => round($p, 4), 'recall' => round($r, 4), 'f1' => round($f, 4), 'support' => $support, 'tp' => $tp, 'fp' => $fp, 'fn' => $fn];
            if ($support > 0 || $fp > 0) {
                $occurring++;
                $macroP += $p;
                $macroR += $r;
                $macroF += $f;
            }
            $weightedF += $n > 0 ? $f * $support / $n : 0;
        }

        return [
            'accuracy' => $n ? round($correct / $n, 4) : 0.0,
            'macro_precision' => $occurring ? round($macroP / $occurring, 4) : 0.0,
            'macro_recall' => $occurring ? round($macroR / $occurring, 4) : 0.0,
            'macro_f1' => $occurring ? round($macroF / $occurring, 4) : 0.0,
            'weighted_f1' => round($weightedF, 4),
            'per_class' => $perClass,
            'confusion_matrix' => ['labels' => $labels, 'matrix' => $matrix],
            'support' => $n,
        ];
    }

    /**
     * Binary precision/recall/F1 for a "positive" condition.
     *
     * @param bool[] $expectedPositive
     * @param bool[] $predictedPositive
     * @return array{precision:float, recall:float, f1:float, tp:int, fp:int, fn:int, tn:int}
     */
    public function binary(array $expectedPositive, array $predictedPositive): array
    {
        $tp = $fp = $fn = $tn = 0;
        $n = min(count($expectedPositive), count($predictedPositive));
        for ($i = 0; $i < $n; $i++) {
            if ($expectedPositive[$i] && $predictedPositive[$i]) {
                $tp++;
            } elseif (!$expectedPositive[$i] && $predictedPositive[$i]) {
                $fp++;
            } elseif ($expectedPositive[$i] && !$predictedPositive[$i]) {
                $fn++;
            } else {
                $tn++;
            }
        }
        $p = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $r = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;

        return ['precision' => round($p, 4), 'recall' => round($r, 4), 'f1' => ($p + $r) > 0 ? round(2 * $p * $r / ($p + $r), 4) : 0.0, 'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn];
    }

    /**
     * Flatten metrics into metric_name => value rows (per-class and matrix go to metadata).
     *
     * @return array<string, array{value: float|null, metadata: array|null}>
     */
    public function toResultRows(array $m, string $prefix = ''): array
    {
        $rows = [];
        foreach (['accuracy', 'macro_precision', 'macro_recall', 'macro_f1', 'weighted_f1'] as $k) {
            $rows[$prefix . $k] = ['value' => $m[$k], 'metadata' => null];
        }
        $rows[$prefix . 'support'] = ['value' => $m['support'], 'metadata' => null];
        $rows[$prefix . 'per_class'] = ['value' => null, 'metadata' => $m['per_class']];
        $rows[$prefix . 'confusion_matrix'] = ['value' => null, 'metadata' => $m['confusion_matrix']];

        return $rows;
    }
}
