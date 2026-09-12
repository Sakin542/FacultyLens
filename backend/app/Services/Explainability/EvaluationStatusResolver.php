<?php

namespace App\Services\Explainability;

use App\Models\AiEvaluationRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Connects an AI result type to its STEP 35/44 evaluation status without exposing benchmark internals.
 */
class EvaluationStatusResolver
{
    public const EVALUATED = 'EVALUATED';
    public const AVAILABLE = 'EVALUATION_AVAILABLE';
    public const LIMITED = 'LIMITED_EVALUATION_DATA';
    public const NOT_EVALUATED = 'NOT_EVALUATED';

    protected const LABELS = [
        self::EVALUATED => 'Evaluated',
        self::AVAILABLE => 'Evaluation available',
        self::LIMITED => 'Limited evaluation data',
        self::NOT_EVALUATED => 'Not evaluated yet',
    ];

    public function resolve(string $resultType): array
    {
        $task = config("ai_explainability.evaluation_tasks.{$resultType}");
        if (!$task) {
            return $this->payload(self::NOT_EVALUATED, null, null);
        }

        return Cache::remember("explainability:evaluation:{$task}", 300, function () use ($task) {
            if (!Schema::hasTable('ai_evaluation_runs')) {
                return $this->payload(self::NOT_EVALUATED, $task, null);
            }
            $run = AiEvaluationRun::query()
                ->where('task', $task)
                ->where('status', AiEvaluationRun::STATUS_COMPLETED)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->first();
            if (!$run) {
                return $this->payload(self::NOT_EVALUATED, $task, null);
            }
            $summary = $run->summary ?? [];
            $minExamples = 30;
            $status = self::AVAILABLE;
            if (($run->example_count ?? 0) < $minExamples) {
                $status = self::LIMITED;
            } elseif (in_array($run->gate_status, [AiEvaluationRun::GATE_PASSED, AiEvaluationRun::GATE_WARNINGS], true)) {
                $status = self::EVALUATED;
            }

            return $this->payload($status, $task, [
                'run_id' => $run->id,
                'gate_status' => $run->gate_status,
                'example_count' => $run->example_count,
                'headline_metric' => $summary['headline_metric'] ?? null,
                'headline_value' => isset($summary['headline_value']) ? (float) $summary['headline_value'] : null,
                'completed_at' => $run->completed_at?->toIso8601String(),
            ]);
        });
    }

    protected function payload(string $status, ?string $task, ?array $summary): array
    {
        return array_merge(['status' => $status, 'label' => self::LABELS[$status], 'task' => $task], $summary ?? []);
    }
}
