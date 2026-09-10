<?php

namespace App\Services\AiEvaluation;

use Illuminate\Support\Collection;

/**
 * STEP 35: Question type / difficulty / Bloom classification (STEP 10 analyzers via batch analyze-questions).
 * Example: input {question}, expected {expected_type | expected_difficulty | expected_cognitive_level}.
 */
class ClassificationTaskEvaluator extends TaskEvaluator
{
    protected const FIELDS = [
        'QUESTION_CLASSIFICATION' => ['expected_type', 'classification', 'question_type', 'WRONG_CLASS'],
        'DIFFICULTY_CLASSIFICATION' => ['expected_difficulty', 'difficulty', 'level', 'WRONG_DIFFICULTY'],
        'BLOOM_CLASSIFICATION' => ['expected_cognitive_level', 'cognitive_level', 'level', 'WRONG_BLOOM_LEVEL'],
    ];

    public function __construct(\App\Services\AiService $ai, ClassificationEvaluator $classification, protected string $taskName) {
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
        $this->requireString($input, 'question', $errors, 'question text');
        $this->requireLabel($expected, self::FIELDS[$this->taskName][0], $this->labelSet($this->taskName), $errors, 'expected label');

        return $errors;
    }

    public function predict(Collection $examples): array
    {
        [$expectedKey, $section, $field, $errorType] = self::FIELDS[$this->taskName];
        $out = [];
        foreach ($examples->chunk((int) config('ai_evaluation.batch_size', 32)) as $batch) {
            $batch = $batch->values();
            try {
                $res = $this->ai->analyzeQuestions($batch->map(fn ($e, $i) => ['number' => $i + 1, 'text' => $e->input_data['question']])->all(),
                    array_values(array_unique(array_filter($batch->map(fn ($e) => $e->input_data['topic'] ?? null)->all()))));
                $items = collect($res['questions'] ?? [])->keyBy('number');
            } catch (\Throwable $e) {
                foreach ($batch as $ex) {
                    $out[$ex->id] = $this->inferenceError($e);
                }
                continue;
            }
            foreach ($batch as $i => $ex) {
                $item = $items->get($i + 1);
                $pred = strtoupper((string) ($item[$section][$field] ?? ''));
                $exp = strtoupper((string) $ex->expected_output[$expectedKey]);
                if ($pred === '') {
                    $out[$ex->id] = ['prediction' => null, 'is_correct' => null, 'score' => null, 'error_type' => 'INFERENCE_ERROR', 'metadata' => null];
                    continue;
                }
                $ok = $pred === $exp;
                $out[$ex->id] = ['prediction' => ['label' => $pred, 'confidence' => $item[$section]['confidence'] ?? null, 'method' => $item[$section]['method'] ?? null],
                    'is_correct' => $ok, 'score' => $ok ? 1.0 : 0.0, 'error_type' => $ok ? null : $errorType, 'metadata' => ['expected' => $exp]];
            }
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        $expectedKey = self::FIELDS[$this->taskName][0];
        $exp = $pred = [];
        foreach ($examples as $ex) {
            $p = $predictions[$ex->id] ?? null;
            if (!$p || $p['prediction'] === null) {
                continue;
            }
            $exp[] = strtoupper($ex->expected_output[$expectedKey]);
            $pred[] = $p['prediction']['label'];
        }
        $m = $this->classification->metrics($exp, $pred, $this->labelSet($this->taskName));
        $rows = $this->classification->toResultRows($m);
        $rows['inference_failures'] = $this->row(count($examples) - count($exp));

        return $rows;
    }
}
