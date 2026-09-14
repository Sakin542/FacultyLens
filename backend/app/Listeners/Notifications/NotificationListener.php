<?php

namespace App\Listeners\Notifications;

use App\Services\Notification\NotificationRecipientResolver;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * STEP 47: base for notification listeners. Every handler runs inside guard() so a notification problem is logged
 * and swallowed — it can never fail or roll back the academic operation that raised the event.
 */
abstract class NotificationListener
{
    public function __construct(
        protected NotificationService $notifications,
        protected NotificationRecipientResolver $recipients,
    ) {}

    protected function guard(string $context, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning("Notification listener [{$context}] failed: " . $e->getMessage(), ['exception' => get_class($e)]);
        }
    }

    protected function quote(?string $text, string $fallback = 'this item'): string
    {
        $text = trim((string) $text);

        return $text === '' ? $fallback : $text;
    }
}
