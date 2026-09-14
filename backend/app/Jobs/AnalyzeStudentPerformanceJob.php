<?php

namespace App\Jobs;

use App\Models\PerformanceAnalysisRun;
use App\Services\StudentPerformanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 30: Computes a student performance snapshot asynchronously (used for large assessments).
 */
class AnalyzeStudentPerformanceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [20];
    public int $timeout = 300;
    public int $uniqueFor = 600;

    public function __construct(protected int $runId, protected int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(StudentPerformanceService $service): void
    {
        $run = PerformanceAnalysisRun::find($this->runId);
        if (!$run) {
            Log::warning("AnalyzeStudentPerformanceJob: run {$this->runId} not found - skipping.");
            return;
        }
        if ($run->status !== PerformanceAnalysisRun::STATUS_PENDING) {
            Log::info("AnalyzeStudentPerformanceJob: run {$run->id} is {$run->status} - skipping.");
            return;
        }
        $service->compute($run);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("AnalyzeStudentPerformanceJob: permanently failed for run {$this->runId}: " . get_class($exception));
        $run = PerformanceAnalysisRun::find($this->runId);
        if ($run && $run->isActive()) {
            $run->update([
                'status' => PerformanceAnalysisRun::STATUS_FAILED,
                'error_message' => 'The performance analysis could not be completed. Please try again.',
            ]);
        }
    }
}
