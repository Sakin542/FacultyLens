<?php

namespace Tests\Unit;

use App\Services\AiEvaluation\ClassificationEvaluator;
use PHPUnit\Framework\TestCase;

/** STEP 35: hand-verified metric values. */
class ClassificationEvaluatorTest extends TestCase
{
    public function test_metrics_match_manual_calculation(): void
    {
        // Expected [A,A,B,B], predicted [A,B,B,B]
        $m = (new ClassificationEvaluator)->metrics(['A', 'A', 'B', 'B'], ['A', 'B', 'B', 'B'], ['A', 'B']);
        $this->assertSame(0.75, $m['accuracy']);
        // A: tp=1 fp=0 fn=1 → P=1, R=0.5, F1=0.6667 ; B: tp=2 fp=1 fn=0 → P=0.6667, R=1, F1=0.8
        $a = $m['per_class'][0];
        $b = $m['per_class'][1];
        $this->assertSame(['A', 1.0, 0.5, 0.6667, 2], [$a['label'], $a['precision'], $a['recall'], $a['f1'], $a['support']]);
        $this->assertSame(['B', 0.6667, 1.0, 0.8, 2], [$b['label'], $b['precision'], $b['recall'], $b['f1'], $b['support']]);
        $this->assertSame(0.7333, $m['macro_f1']);
        $this->assertSame(0.7333, $m['weighted_f1']); // equal support
        $this->assertSame(0.8333, $m['macro_precision']);
        $this->assertSame(0.75, $m['macro_recall']);
        $this->assertSame([[1, 1], [0, 2]], $m['confusion_matrix']['matrix']);
        $this->assertSame(['A', 'B'], $m['confusion_matrix']['labels']);
    }

    public function test_unseen_labels_and_empty_input(): void
    {
        $m = (new ClassificationEvaluator)->metrics(['EASY', 'HARD'], ['EASY', 'MEDIUM'], ['EASY', 'MEDIUM', 'HARD']);
        $this->assertSame(0.5, $m['accuracy']);
        $this->assertSame(3, count($m['per_class']));
        $this->assertSame(0, $m['per_class'][1]['support']); // MEDIUM never expected
        $empty = (new ClassificationEvaluator)->metrics([], [], ['A']);
        $this->assertSame(0.0, $empty['accuracy']);
        $this->assertSame(0, $empty['support']);
    }

    public function test_binary_metrics(): void
    {
        $b = (new ClassificationEvaluator)->binary([true, true, false, false, true], [true, false, true, false, true]);
        $this->assertSame(['tp' => 2, 'fp' => 1, 'fn' => 1, 'tn' => 1], array_intersect_key($b, array_flip(['tp', 'fp', 'fn', 'tn'])));
        $this->assertSame(0.6667, $b['precision']);
        $this->assertSame(0.6667, $b['recall']);
        $this->assertSame(0.6667, $b['f1']);
    }
}
