<?php

namespace App\Jobs;

use App\Models\QuestionGenerationRequest;
use App\Services\QuestionGenerationService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * STEP 33: Runs a question generation request off the HTTP cycle. Idempotent via request status.
 */
class GenerateQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30];
    public int $timeout = 300;

    public function __construct(public int $requestId) {}

    public function handle(QuestionGenerationService $service): void
    {
        $request = QuestionGenerationRequest::find($this->requestId);
        if (!$request) {
            return;
        }

        try {
            $service->run($request);
        } catch (Exception $e) {
            Log::warning("GenerateQuestionsJob: request {$this->requestId} failed (attempt {$this->attempts()}): {$e->getMessage()}");
            // Retry with backoff without bubbling the exception into a synchronous dispatcher (tests / sync queue).
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[0]);
            }
        }
    }

    public function failed(Exception $exception): void
    {
        QuestionGenerationRequest::where('id', $this->requestId)
            ->whereIn('generation_status', [QuestionGenerationRequest::STATUS_PENDING, QuestionGenerationRequest::STATUS_PROCESSING])
            ->update([
                'generation_status' => QuestionGenerationRequest::STATUS_FAILED,
                'error_message' => 'Question generation failed after retries. Please try again.',
            ]);
    }
}
