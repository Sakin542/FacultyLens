<?php

namespace App\Jobs;

use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 47: writes one notification row. Runs on the project's queue (database in dev, Redis/Horizon in production).
 *
 * Idempotent: NotificationService::store() checks unique(user_id, dedupe_key), so a retried job (Redis retry_after,
 * worker crash, transient DB error) can never produce a duplicate. Failure here never affects the academic
 * operation that emitted the event — that already completed before the job was queued.
 */
class StoreNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    /**
     * @param array<string, mixed> $payload normalised by NotificationService::buildPayload()
     */
    public function __construct(public array $payload)
    {
        $this->tries = max(1, min(5, (int) config('notifications.queue.tries', 3)));
        $this->backoff = (array) config('notifications.queue.backoff', [10, 30, 60]);
    }

    public function handle(NotificationService $service): void
    {
        $service->store($this->payload);
    }

    public function failed(Throwable $e): void
    {
        Log::error('StoreNotificationJob: permanently failed', [
            'type' => $this->payload['type'] ?? null,
            'user_id' => $this->payload['user_id'] ?? null,
            'dedupe_key' => $this->payload['dedupe_key'] ?? null,
            'error' => get_class($e),
        ]);
    }

    /** Lets Horizon / queue:monitor group notification writes. */
    public function tags(): array
    {
        return ['notification', 'type:' . ($this->payload['type'] ?? 'unknown')];
    }
}
