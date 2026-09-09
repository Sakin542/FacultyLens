<?php

namespace App\Jobs;

use App\Models\AiGradingResult;
use App\Services\AiGradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 27: Runs one AI grading evaluation asynchronously.
 * Idempotent: only PENDING results are processed; duplicates for the same result are not queued.
 * Student answer text is never logged.
 */
class GradeAnswerJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [20];
    public int $timeout = 180;
    public int $uniqueFor = 300;

    public function __construct(
        protected int $resultId,
        protected int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->resultId;
    }

    public function handle(AiGradingService $service): void
    {
        $result = AiGradingResult::find($this->resultId);

        if (!$result) {
            Log::warning("GradeAnswerJob: result {$this->resultId} not found - skipping.");
            return;
        }

        if ($result->grading_status !== AiGradingResult::STATUS_PENDING) {
            Log::info("GradeAnswerJob: result {$result->id} is {$result->grading_status} - skipping.");
            return;
        }

        $service->process($result);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("GradeAnswerJob: permanently failed for result {$this->resultId}: " . get_class($exception));

        $result = AiGradingResult::find($this->resultId);
        if ($result && $result->isActive()) {
            $result->update([
                'grading_status' => AiGradingResult::STATUS_FAILED,
                'error_message' => AiGradingService::MSG_UNAVAILABLE,
            ]);
        }
    }
}
