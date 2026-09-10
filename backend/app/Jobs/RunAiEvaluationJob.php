<?php

namespace App\Jobs;

use App\Models\AiEvaluationRun;
use App\Services\AiEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * STEP 35: runs an evaluation off the HTTP cycle. Idempotent — predictions/results are upserted per (run, example).
 */
class RunAiEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 1800;

    public function __construct(public int $runId) {}

    public function handle(AiEvaluationService $service): void
    {
        $run = AiEvaluationRun::find($this->runId);
        if (!$run) {
            return;
        }
        try {
            $service->executeRun($run);
        } catch (\Throwable $e) {
            Log::warning("RunAiEvaluationJob: run {$this->runId} failed (attempt {$this->attempts()}): {$e->getMessage()}");
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[0]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        AiEvaluationRun::where('id', $this->runId)->whereIn('status', [AiEvaluationRun::STATUS_PENDING, AiEvaluationRun::STATUS_RUNNING])
            ->update(['status' => AiEvaluationRun::STATUS_FAILED, 'failure_reason' => 'Evaluation failed after retries.', 'completed_at' => now()]);
    }
}
