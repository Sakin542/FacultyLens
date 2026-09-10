<?php

namespace App\Services\AiEvaluation;

use App\Services\AcademicDocumentRetrievalService;
use Illuminate\Support\Collection;

/**
 * STEP 35: semantic similarity (STEP 12). Example: input {question_a, question_b}, expected {expected_relationship}.
 * Reports classification at the production thresholds plus an evaluation-only threshold sweep for the
 * "potential duplicate" decision. Production thresholds are never modified.
 */
class SimilarityEvaluator extends TaskEvaluator
{
    public function task(): string
    {
        return 'SIMILARITY';
    }

    public function headlineMetric(): string
    {
        return 'duplicate_f1';
    }

    public function validateExample(array $input, array $expected): array
    {
        $errors = [];
        $this->requireString($input, 'question_a', $errors, 'question A');
        $this->requireString($input, 'question_b', $errors, 'question B');
        $this->requireLabel($expected, 'expected_relationship', $this->labelSet('SIMILARITY'), $errors, 'expected relationship');

        return $errors;
    }

    public static function status(float $score, array $t): string
    {
        if ($score >= $t['duplicate']) {
            return 'POTENTIAL_DUPLICATE';
        }
        if ($score >= $t['high']) {
            return 'HIGHLY_SIMILAR';
        }

        return $score >= $t['moderate'] ? 'SOMEWHAT_SIMILAR' : 'NOT_SIMILAR';
    }

    public function predict(Collection $examples): array
    {
        $t = config('ai_evaluation.similarity_thresholds');
        $out = [];
        foreach ($examples->chunk(max(1, intdiv((int) config('ai_evaluation.batch_size', 32), 2))) as $batch) {
            $batch = $batch->values();
            try {
                $vectors = $this->ai->generateEmbeddings($batch->flatMap(fn ($e) => [$e->input_data['question_a'], $e->input_data['question_b']])->all())['embeddings'];
            } catch (\Throwable $e) {
                foreach ($batch as $ex) {
                    $out[$ex->id] = $this->inferenceError($e);
                }
                continue;
            }
            foreach ($batch as $i => $ex) {
                $score = AcademicDocumentRetrievalService::cosine($vectors[$i * 2], $vectors[$i * 2 + 1]);
                $pred = self::status($score, $t);
                $exp = strtoupper($ex->expected_output['expected_relationship']);
                $ok = $pred === $exp;
                $expDup = $exp === 'POTENTIAL_DUPLICATE';
                $predDup = $pred === 'POTENTIAL_DUPLICATE';
                $error = null;
                if (!$ok) {
                    if ($predDup && !$expDup) {
                        $error = 'FALSE_DUPLICATE';
                    } elseif (!$predDup && $expDup) {
                        $error = 'MISSED_DUPLICATE';
                    } else {
                        $error = 'WRONG_CLASS';
                    }
                }
                $out[$ex->id] = ['prediction' => ['label' => $pred, 'similarity_score' => round($score, 4)], 'is_correct' => $ok, 'score' => round($score, 4),
                    'error_type' => $error, 'metadata' => ['expected' => $exp]];
            }
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        $t = config('ai_evaluation.similarity_thresholds');
        $exp = $pred = $scores = [];
        foreach ($examples as $ex) {
            $p = $predictions[$ex->id] ?? null;
            if ($p && $p['prediction']) {
                $exp[] = strtoupper($ex->expected_output['expected_relationship']);
                $pred[] = $p['prediction']['label'];
                $scores[] = (float) $p['prediction']['similarity_score'];
            }
        }
        $rows = $this->classification->toResultRows($this->classification->metrics($exp, $pred, $this->labelSet('SIMILARITY')));

        // Binary duplicate detection at the production threshold
        $expDup = array_map(fn ($l) => $l === 'POTENTIAL_DUPLICATE', $exp);
        $b = $this->classification->binary($expDup, array_map(fn ($l) => $l === 'POTENTIAL_DUPLICATE', $pred));
        $rows['duplicate_precision'] = $this->row($b['precision']);
        $rows['duplicate_recall'] = $this->row($b['recall']);
        $rows['duplicate_f1'] = $this->row($b['f1']);
        // "Similar or closer" (>= high threshold) detection
        $expSim = array_map(fn ($l) => in_array($l, ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR'], true), $exp);
        $bs = $this->classification->binary($expSim, array_map(fn ($s) => $s >= $t['high'], $scores));
        $rows['similar_precision'] = $this->row($bs['precision']);
        $rows['similar_recall'] = $this->row($bs['recall']);
        $rows['similar_f1'] = $this->row($bs['f1']);

        // Evaluation-only sweep: how duplicate detection would behave at other thresholds
        $sweep = [];
        foreach ((array) config('ai_evaluation.similarity_sweep') as $th) {
            $bb = $this->classification->binary($expDup, array_map(fn ($s) => $s >= $th, $scores));
            $sweep[] = ['threshold' => $th, 'precision' => $bb['precision'], 'recall' => $bb['recall'], 'f1' => $bb['f1']];
        }
        $rows['threshold_sweep'] = ['value' => null, 'metadata' => ['positive' => 'POTENTIAL_DUPLICATE', 'production_threshold' => $t['duplicate'], 'rows' => $sweep]];
        $rows['thresholds'] = ['value' => null, 'metadata' => $t];
        $rows['mean_similarity'] = $this->row($scores ? array_sum($scores) / count($scores) : 0);

        return $rows;
    }
}
