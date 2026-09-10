<?php

namespace App\Services\AiEvaluation;

use App\Models\AiEvaluationExample;
use App\Services\AiService;
use Illuminate\Support\Collection;

/**
 * STEP 35: contract for task-specific evaluators. Inference reuses the production AI service; nothing here
 * changes production behaviour.
 */
abstract class TaskEvaluator
{
    public function __construct(protected AiService $ai, protected ClassificationEvaluator $classification) {}

    abstract public function task(): string;

    /**
     * Validate one example's shape/labels. Return a list of human-readable problems (empty = valid).
     *
     * @return string[]
     */
    abstract public function validateExample(array $input, array $expected): array;

    /**
     * Run inference + per-example scoring. Returns rows keyed by example id:
     * ['prediction'=>array, 'is_correct'=>?bool, 'score'=>?float, 'error_type'=>?string, 'metadata'=>?array]
     *
     * @param Collection<int, AiEvaluationExample> $examples
     * @return array<int, array<string, mixed>>
     */
    abstract public function predict(Collection $examples): array;

    /**
     * Aggregate metrics from example predictions. metric_name => ['value'=>float|null,'metadata'=>array|null]
     *
     * @param Collection<int, AiEvaluationExample> $examples
     * @param array<int, array<string, mixed>> $predictions
     * @return array<string, array{value: float|null, metadata: array|null}>
     */
    abstract public function aggregate(Collection $examples, array $predictions): array;

    /** The headline metric shown on dashboards. */
    abstract public function headlineMetric(): string;

    protected function labelSet(string $task): array
    {
        return (array) config("ai_evaluation.labels.{$task}", []);
    }

    protected function requireString(array $data, string $key, array &$errors, string $label): void
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            $errors[] = "Missing {$label} ({$key}).";
        }
    }

    protected function requireLabel(array $data, string $key, array $allowed, array &$errors, string $label): void
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            $errors[] = "Missing {$label} ({$key}).";
        } elseif (!in_array(strtoupper($data[$key]), $allowed, true)) {
            $errors[] = "Invalid {$label} '{$data[$key]}' (allowed: " . implode(', ', $allowed) . ').';
        }
    }

    protected function inferenceError(\Throwable $e): array
    {
        return ['prediction' => null, 'is_correct' => null, 'score' => null, 'error_type' => 'INFERENCE_ERROR', 'metadata' => ['message' => substr($e->getMessage(), 0, 200)]];
    }

    /** @return array{value: float|null, metadata: array|null} */
    protected function row(float|int|null $value, ?array $meta = null): array
    {
        return ['value' => $value === null ? null : round((float) $value, 6), 'metadata' => $meta];
    }
}
