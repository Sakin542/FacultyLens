<?php

namespace App\Jobs;

use App\Mail\FacultyLensMail;
use App\Models\EmailDelivery;
use App\Models\Notification;
use App\Services\AuditLogService;
use App\Services\Email\EmailErrorClassifier;
use App\Services\Email\EmailLogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one email_deliveries row through the configured mailer (Gmail SMTP in production).
 *
 *   PENDING → PROCESSING → SENT
 *                        → FAILED   (permanent error, or retries exhausted)
 *
 * Idempotent: a re-delivered job whose row is already SENT/CANCELLED exits without sending. Temporary transport
 * errors are re-thrown so the queue retries with backoff; permanent errors (bad address, rejected credentials,
 * template errors) fail immediately. Nothing sensitive is ever logged — messages pass through EmailLogSanitizer.
 */
class SendFacultyLensEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;
    public array $backoff = [30, 120, 600];
    public int $timeout = 90;
    public int $maxExceptions = 4;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $deliveryId,
        public string $template,
        public string $subject,
        public array $context = [],
    ) {
        $this->tries = max(1, min(6, (int) config('email.queue.tries', 4)));
        $this->backoff = (array) config('email.queue.backoff', [30, 120, 600]);
        $this->timeout = max(15, (int) config('email.queue.timeout', 90));
        $this->maxExceptions = $this->tries;
    }

    public function handle(AuditLogService $audit): void
    {
        $delivery = EmailDelivery::query()->find($this->deliveryId);
        if (!$delivery) {
            Log::warning('SendFacultyLensEmailJob: delivery row missing', ['delivery_id' => $this->deliveryId]);

            return;
        }
        if ($delivery->isFinal()) {
            return; // already SENT / FAILED / CANCELLED — a redelivered job must not send twice
        }

        $delivery->forceFill(['status' => EmailDelivery::PROCESSING, 'attempts' => $delivery->attempts + 1])->save();
        $this->linkNotification($delivery);

        try {
            $mailable = new FacultyLensMail($this->template, $this->subject, $this->context, $delivery->id, $delivery->type);
            $sent = Mail::to($delivery->recipient)->send($mailable);
            $messageId = null;
            if ($sent && method_exists($sent, 'getMessageId')) {
                $messageId = $sent->getMessageId();
            }

            $delivery->forceFill([
                'status' => EmailDelivery::SENT,
                'sent_at' => now(),
                'message_id' => $messageId ? mb_substr((string) $messageId, 0, 255) : null,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            $audit->log('EMAIL_SENT', 'EmailDelivery', $delivery->id, $this->auditMeta($delivery), $delivery->user);
            Log::info('Email sent', $this->logMeta($delivery));
        } catch (Throwable $e) {
            $classified = EmailErrorClassifier::classify($e);
            $exhausted = $delivery->attempts >= $this->tries;

            if ($classified['permanent'] || $exhausted) {
                $this->markFailed($delivery, $classified['code'], $classified['message'], $audit);
                if ($classified['permanent']) {
                    // do not retry a bad address / rejected credentials / broken template
                    $this->fail($e);

                    return;
                }
                throw $e; // exhausted: let the worker record the failed job
            }

            // temporary: keep the row visible as PENDING with the last error, then let the queue retry with backoff
            $delivery->forceFill(['status' => EmailDelivery::PENDING, 'error_code' => $classified['code'], 'error_message' => $classified['message']])->save();
            Log::warning('Email send failed; will retry', $this->logMeta($delivery) + ['attempt' => $delivery->attempts, 'error_code' => $classified['code']]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $delivery = EmailDelivery::query()->find($this->deliveryId);
        if ($delivery && !$delivery->isFinal()) {
            $classified = EmailErrorClassifier::classify($e);
            $this->markFailed($delivery, $classified['code'], $classified['message'], app(AuditLogService::class));
        }
        Log::error('SendFacultyLensEmailJob: permanently failed', EmailLogSanitizer::context([
            'delivery_id' => $this->deliveryId, 'template' => $this->template, 'error' => get_class($e), 'detail' => $e->getMessage(),
        ]));
    }

    public function tags(): array
    {
        return ['email', 'template:' . $this->template, 'delivery:' . $this->deliveryId];
    }

    protected function markFailed(EmailDelivery $delivery, string $code, string $message, AuditLogService $audit): void
    {
        $delivery->forceFill([
            'status' => EmailDelivery::FAILED,
            'failed_at' => now(),
            'error_code' => $code,
            'error_message' => EmailLogSanitizer::string($message),
        ])->save();
        try {
            $audit->log('EMAIL_FAILED', 'EmailDelivery', $delivery->id, $this->auditMeta($delivery) + ['error_code' => $code], $delivery->user);
        } catch (Throwable) {
            // auditing must never mask the original failure
        }
        Log::error('Email delivery failed', $this->logMeta($delivery) + ['error_code' => $code]);
    }

    /** The in-app row is written by its own job; by send time it usually exists and can be referenced for tracing. */
    protected function linkNotification(EmailDelivery $delivery): void
    {
        if ($delivery->notification_id !== null || !$delivery->user_id) {
            return;
        }
        $prefix = "notification:{$delivery->user_id}:";
        $key = (string) $delivery->getAttribute('idempotency_key');
        if (!str_starts_with($key, $prefix)) {
            return;
        }
        try {
            $id = Notification::query()->where('user_id', $delivery->user_id)->where('dedupe_key', substr($key, strlen($prefix)))->value('id');
            if ($id) {
                $delivery->forceFill(['notification_id' => $id])->save();
            }
        } catch (Throwable) {
            // tracing only
        }
    }

    /** @return array<string, mixed> */
    protected function auditMeta(EmailDelivery $delivery): array
    {
        return [
            'type' => $delivery->type,
            'template' => $delivery->template,
            'recipient_domain' => EmailLogSanitizer::domain($delivery->recipient),
            'attempts' => $delivery->attempts,
            'status' => $delivery->status,
            'notification_id' => $delivery->notification_id,
        ];
    }

    /** @return array<string, mixed> */
    protected function logMeta(EmailDelivery $delivery): array
    {
        return [
            'delivery_id' => $delivery->id,
            'type' => $delivery->type,
            'recipient_domain' => EmailLogSanitizer::domain($delivery->recipient),
            'status' => $delivery->status,
        ];
    }
}
