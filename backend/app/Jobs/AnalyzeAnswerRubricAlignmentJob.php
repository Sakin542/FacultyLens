<?php

namespace App\Jobs;

use App\Models\AnswerRubricAlignment;
use App\Services\RubricAlignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 28: Runs one Answer <-> Rubric alignment analysis asynchronously.
 * Idempotent: only PENDING records are processed. Student answer text is never logged.
 */
class AnalyzeAnswerRubricAlignmentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [20];
    public int $timeout = 180;
    public int $uniqueFor = 300;

    public function __construct(
        protected int $alignmentId,
        protected int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->alignmentId;
    }

    public function handle(RubricAlignmentService $service): void
    {
        $alignment = AnswerRubricAlignment::find($this->alignmentId);

        if (!$alignment) {
            Log::warning("AnalyzeAnswerRubricAlignmentJob: alignment {$this->alignmentId} not found - skipping.");
            return;
        }

        if ($alignment->analysis_status !== AnswerRubricAlignment::STATUS_PENDING) {
            Log::info("AnalyzeAnswerRubricAlignmentJob: alignment {$alignment->id} is {$alignment->analysis_status} - skipping.");
            return;
        }

        $service->process($alignment);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("AnalyzeAnswerRubricAlignmentJob: permanently failed for alignment {$this->alignmentId}: " . get_class($exception));

        $alignment = AnswerRubricAlignment::find($this->alignmentId);
        if ($alignment && $alignment->isActive()) {
            $alignment->update([
                'analysis_status' => AnswerRubricAlignment::STATUS_FAILED,
                'error_message' => RubricAlignmentService::MSG_UNAVAILABLE,
            ]);
        }
    }
}
