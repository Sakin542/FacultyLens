<?php

namespace App\Services\AiEvaluation;

use App\Services\AcademicDocumentRetrievalService;
use Illuminate\Support\Collection;

/**
 * STEP 35: alignment evaluation.
 *  - LO_ALIGNMENT: input {question, learning_outcome}, expected {expected_alignment: STRONG|WEAK|NOT_ALIGNED}.
 *    Uses MiniLM embeddings + cosine with the production STEP 11 thresholds (batched, never edits thresholds).
 *  - ANSWER_RUBRIC_ALIGNMENT: input {answer, question, rubric{criteria[{id,criterion,description,max_marks}]}}
 *    (or offline {ai_criterion_statuses:{id:status}}), expected {criterion_statuses:{id: status}}.
 *    Criterion-level precision/recall/F1 (positive = aligned) + overall agreement.
 */
class AlignmentEvaluator extends TaskEvaluator
{
    public function __construct(\App\Services\AiService $ai, ClassificationEvaluator $classification, protected string $taskName = 'LO_ALIGNMENT') {
        parent::__construct($ai, $classification);
    }

    public function task(): string
    {
        return $this->taskName;
    }

    public function headlineMetric(): string
    {
        return 'macro_f1';
    }

    public function validateExample(array $input, array $expected): array
    {
        $errors = [];
        if ($this->taskName === 'LO_ALIGNMENT') {
            $this->requireString($input, 'question', $errors, 'question text');
            $this->requireString($input, 'learning_outcome', $errors, 'learning outcome description');
            $this->requireLabel($expected, 'expected_alignment', $this->labelSet('LO_ALIGNMENT'), $errors, 'expected alignment');

            return $errors;
        }
        if (!is_array($expected['criterion_statuses'] ?? null) || $expected['criterion_statuses'] === []) {
            $errors[] = 'Missing expected criterion_statuses map.';
        } else {
            foreach ($expected['criterion_statuses'] as $id => $status) {
                if (!in_array(strtoupper((string) $status), $this->labelSet('ANSWER_RUBRIC_ALIGNMENT'), true)) {
                    $errors[] = "Invalid criterion status '{$status}' for criterion {$id}.";
                }
            }
        }
        if (!isset($input['ai_criterion_statuses'])) {
            $this->requireString($input, 'answer', $errors, 'student answer text');
            $this->requireString($input, 'question', $errors, 'question text');
            if (!is_array($input['rubric']['criteria'] ?? null) || $input['rubric']['criteria'] === []) {
                $errors[] = 'Missing rubric.criteria for live alignment.';
            }
        }

        return $errors;
    }

    public function predict(Collection $examples): array
    {
        return $this->taskName === 'LO_ALIGNMENT' ? $this->predictLo($examples) : $this->predictRubric($examples);
    }

    public static function status(float $score, array $t): string
    {
        if ($score >= $t['strong']) {
            return 'STRONG';
        }
        if ($score >= $t['weak']) {
            return 'WEAK';
        }

        return 'NOT_ALIGNED';
    }

    protected function predictLo(Collection $examples): array
    {
        $t = config('ai_evaluation.alignment_thresholds');
        $out = [];
        foreach ($examples->chunk(max(1, intdiv((int) config('ai_evaluation.batch_size', 32), 2))) as $batch) {
            $batch = $batch->values();
            try {
                $texts = $batch->flatMap(fn ($e) => [$e->input_data['question'], $e->input_data['learning_outcome']])->all();
                $vectors = $this->ai->generateEmbeddings($texts)['embeddings'];
            } catch (\Throwable $e) {
                foreach ($batch as $ex) {
                    $out[$ex->id] = $this->inferenceError($e);
                }
                continue;
            }
            foreach ($batch as $i => $ex) {
                $score = AcademicDocumentRetrievalService::cosine($vectors[$i * 2], $vectors[$i * 2 + 1]);
                $pred = self::status($score, $t);
                $exp = strtoupper($ex->expected_output['expected_alignment']);
                $ok = $pred === $exp;
                $out[$ex->id] = ['prediction' => ['label' => $pred, 'similarity_score' => round($score, 4)], 'is_correct' => $ok, 'score' => round($score, 4),
                    'error_type' => $ok ? null : 'WRONG_ALIGNMENT', 'metadata' => ['expected' => $exp, 'thresholds' => $t]];
            }
        }

        return $out;
    }

    protected function predictRubric(Collection $examples): array
    {
        $out = [];
        foreach ($examples as $ex) {
            $in = $ex->input_data;
            $expected = array_change_key_case(array_map('strtoupper', $ex->expected_output['criterion_statuses']), CASE_LOWER);
            try {
                if (isset($in['ai_criterion_statuses'])) {
                    $statuses = array_map('strtoupper', $in['ai_criterion_statuses']);
                    $method = 'offline_recorded';
                } else {
                    $criteria = array_values(array_map(fn ($c, $i) => ['id' => (int) ($c['id'] ?? $i + 1), 'criterion' => $c['criterion'] ?? "Criterion " . ($i + 1), 'description' => $c['description'] ?? '',
                        'max_marks' => (float) ($c['max_marks'] ?? 0), 'expected_indicators' => $c['expected_indicators'] ?? []], $in['rubric']['criteria'], array_keys($in['rubric']['criteria'])));
                    $res = $this->ai->analyzeAnswerRubricAlignment([
                        'student_answer' => ['text' => $in['answer']],
                        'question' => ['text' => $in['question'], 'total_marks' => $in['rubric']['total_marks'] ?? array_sum(array_column($criteria, 'max_marks'))],
                        'rubric' => ['total_marks' => $in['rubric']['total_marks'] ?? array_sum(array_column($criteria, 'max_marks')), 'criteria' => $criteria],
                    ]);
                    $statuses = [];
                    foreach ($res['criterion_alignments'] ?? [] as $c) {
                        $statuses[(string) $c['rubric_criterion_id']] = strtoupper($c['alignment_status']);
                    }
                    $method = 'live';
                }
            } catch (\Throwable $e) {
                $out[$ex->id] = $this->inferenceError($e);
                continue;
            }
            $agree = $total = 0;
            $pairs = [];
            foreach ($expected as $id => $exp) {
                $pred = $statuses[(string) $id] ?? $statuses[(int) $id] ?? null;
                if ($pred === null) {
                    continue;
                }
                $total++;
                $agree += (int) ($pred === $exp);
                $pairs[] = ['criterion_id' => $id, 'expected' => $exp, 'predicted' => $pred];
            }
            $agreement = $total ? $agree / $total : 0.0;
            $out[$ex->id] = ['prediction' => ['criterion_statuses' => $statuses, 'pairs' => $pairs, 'method' => $method], 'is_correct' => $total > 0 && $agree === $total,
                'score' => round($agreement, 4), 'error_type' => $agree === $total ? null : 'WRONG_ALIGNMENT', 'metadata' => ['criteria_compared' => $total]];
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        if ($this->taskName === 'LO_ALIGNMENT') {
            $exp = $pred = [];
            foreach ($examples as $ex) {
                $p = $predictions[$ex->id] ?? null;
                if ($p && $p['prediction']) {
                    $exp[] = strtoupper($ex->expected_output['expected_alignment']);
                    $pred[] = $p['prediction']['label'];
                }
            }
            $rows = $this->classification->toResultRows($this->classification->metrics($exp, $pred, $this->labelSet('LO_ALIGNMENT')));
            foreach (['STRONG', 'WEAK', 'NOT_ALIGNED'] as $label) {
                $pc = collect($rows['per_class']['metadata'])->firstWhere('label', $label) ?? ['precision' => 0, 'recall' => 0, 'f1' => 0];
                $rows[strtolower($label) . '_precision'] = $this->row($pc['precision']);
                $rows[strtolower($label) . '_recall'] = $this->row($pc['recall']);
                $rows[strtolower($label) . '_f1'] = $this->row($pc['f1']);
            }
            $rows['thresholds'] = ['value' => null, 'metadata' => config('ai_evaluation.alignment_thresholds')];
            $rows['method'] = ['value' => null, 'metadata' => ['description' => 'MiniLM embeddings + cosine similarity at production STEP 11 thresholds']];

            return $rows;
        }

        // Criterion-level rows across all examples
        $exp = $pred = [];
        $expPos = $predPos = [];
        $aligned = ['FULLY_ALIGNED', 'PARTIALLY_ALIGNED'];
        foreach ($examples as $ex) {
            foreach ($predictions[$ex->id]['prediction']['pairs'] ?? [] as $pair) {
                $exp[] = $pair['expected'];
                $pred[] = $pair['predicted'];
                $expPos[] = in_array($pair['expected'], $aligned, true);
                $predPos[] = in_array($pair['predicted'], $aligned, true);
            }
        }
        $rows = $this->classification->toResultRows($this->classification->metrics($exp, $pred, $this->labelSet('ANSWER_RUBRIC_ALIGNMENT')));
        $b = $this->classification->binary($expPos, $predPos);
        $rows['criterion_precision'] = $this->row($b['precision']);
        $rows['criterion_recall'] = $this->row($b['recall']);
        $rows['criterion_f1'] = $this->row($b['f1']);
        $rows['false_positive_alignments'] = $this->row($b['fp']);
        $rows['false_negative_alignments'] = $this->row($b['fn']);
        $rows['overall_agreement'] = $this->row($rows['accuracy']['value']);
        $rows['criteria_compared'] = $this->row(count($exp));

        return $rows;
    }
}
