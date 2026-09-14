<?php

namespace App\Services\Email;

use App\Jobs\SendFacultyLensEmailJob;
use App\Models\EmailDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The ONLY place FacultyLens queues an e-mail.
 *
 *   NotificationService::notify() ──► queueNotificationEmail(payload)   (academic notifications, preference-gated)
 *   User::sendPasswordResetNotification() ──► sendPasswordReset()       (authentication, always sent)
 *   POST /api/email/test ──► sendTestEmail()                            (operator diagnostics)
 *
 * Every path ends in send(): validate the recipient, collapse duplicates on an idempotency key, apply storm limits,
 * write an email_deliveries row and dispatch SendFacultyLensEmailJob after the surrounding transaction commits.
 * Failures are recorded and logged (sanitised) but never thrown back into an academic workflow.
 */
class EmailService
{
    public const TYPE_PASSWORD_RESET = 'PASSWORD_RESET';
    public const TYPE_TEST = 'TEST_EMAIL';

    /** Types that bypass notification preferences (authentication / diagnostics). */
    public const SYSTEM_TYPES = [self::TYPE_PASSWORD_RESET, self::TYPE_TEST];

    public function __construct(
        protected EmailContentResolver $content,
        protected AuditLogService $audit,
    ) {}

    // ============================================================ decisions

    public function enabled(): bool
    {
        return (bool) config('email.enabled', true);
    }

    /** Preference + policy gate for a notification type. Security categories ignore the stored preference. */
    public function shouldEmail(int $userId, string $type): bool
    {
        if (!$this->enabled() || !NotificationType::isValid($type)) {
            return false;
        }
        if (in_array($type, (array) config('email.never_email_types', []), true)) {
            return false;
        }
        if ($this->isSecurity($type)) {
            return true;
        }

        return NotificationPreference::emailEnabled($userId, $type);
    }

    public function isSecurity(string $type): bool
    {
        if (in_array($type, self::SYSTEM_TYPES, true)) {
            return true;
        }
        $category = NotificationType::isValid($type) ? NotificationType::category($type) : null;

        return $category !== null && in_array($category, (array) config('email.mandatory_categories', []), true);
    }

    // ============================================================= producers

    /**
     * Queue the e-mail counterpart of an in-app notification payload (NotificationService::buildPayload shape).
     * Independent of the in-app write: runs even when the recipient muted the in-app channel, and never throws.
     *
     * @param array<string, mixed> $payload
     */
    public function queueNotificationEmail(array $payload, ?User $recipient = null): ?EmailDelivery
    {
        try {
            $userId = (int) ($payload['user_id'] ?? 0);
            $type = (string) ($payload['type'] ?? '');
            if ($userId <= 0 || !$this->shouldEmail($userId, $type)) {
                return null;
            }
            $recipient ??= User::query()->find($userId);
            if (!$recipient) {
                return null;
            }

            $dedupe = $payload['dedupe_key'] ?? null;
            $key = $dedupe !== null
                ? "notification:{$userId}:" . $dedupe
                : "notification:{$userId}:{$type}:" . sha1(($payload['title'] ?? '') . '|' . ($payload['message'] ?? '') . '|' . now()->format('YmdHi'));

            return $this->send($recipient->email, $type, $this->content->contextForNotification($payload, $recipient), [
                'user' => $recipient,
                'idempotency_key' => $key,
                'subject' => $this->content->subjectFor($type, $payload['title'] ?? null),
                'template' => $this->content->templateFor($type),
                'category' => $payload['category'] ?? $this->content->categoryFor($type),
                'dedupe_key' => $dedupe,
            ]);
        } catch (Throwable $e) {
            Log::warning('EmailService: notification e-mail skipped', EmailLogSanitizer::context([
                'type' => $payload['type'] ?? null, 'user_id' => $payload['user_id'] ?? null, 'error' => get_class($e), 'detail' => $e->getMessage(),
            ]));

            return null;
        }
    }

    /**
     * Send a typed e-mail to a user account (used for events that are not notification types, or by tests).
     *
     * @param array<string, mixed> $context
     * @param array{idempotency_key?:string, subject?:string, template?:string, sync?:bool} $options
     */
    public function sendToUser(User $user, string $type, array $context = [], array $options = []): ?EmailDelivery
    {
        if (NotificationType::isValid($type) && !$this->shouldEmail($user->id, $type)) {
            return null;
        }

        return $this->send($user->email, $type, ['recipient_name' => $user->name] + $context, [
            'user' => $user,
            'subject' => $options['subject'] ?? $this->content->subjectFor($type),
            'template' => $options['template'] ?? $this->content->templateFor($type),
            'category' => $this->content->categoryFor($type),
        ] + $options);
    }

    /**
     * Password-reset e-mail (Laravel password broker supplies the token). Security-critical: bypasses preferences,
     * is never deduplicated against a previous request, and the token appears only inside the reset URL.
     */
    public function sendPasswordReset(User $user, string $token): ?EmailDelivery
    {
        $frontend = rtrim((string) config('email.frontend_url'), '/');
        $resetUrl = $frontend . '/reset-password?' . http_build_query(['token' => $token, 'email' => $user->email]);

        return $this->send($user->email, self::TYPE_PASSWORD_RESET, [
            'recipient_name' => $user->name,
            'reset_url' => $resetUrl,
            'expires_minutes' => (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60),
        ], [
            'user' => $user,
            'idempotency_key' => 'password_reset:' . $user->id . ':' . hash('sha256', $token),
            'subject' => $this->content->subjectFor(self::TYPE_PASSWORD_RESET),
            'template' => $this->content->templateFor(self::TYPE_PASSWORD_RESET),
            'category' => 'AUTH',
        ]);
    }

    /**
     * Operator test message. Always creates a fresh delivery (no dedupe) so the queue → SMTP path can be verified.
     *
     * @throws InvalidArgumentException when the recipient is not a valid address
     */
    public function sendTestEmail(User $requester, string $recipient, bool $sync = false): EmailDelivery
    {
        $delivery = $this->send($recipient, self::TYPE_TEST, [
            'recipient_name' => $requester->email === strtolower(trim($recipient)) ? $requester->name : null,
            'requested_by' => $requester->name,
            'environment' => (string) config('app.env'),
            'sent_at' => now()->toDayDateTimeString(),
        ], [
            'user' => $requester,
            'idempotency_key' => 'test:' . $requester->id . ':' . Str::uuid(),
            'subject' => $this->content->subjectFor(self::TYPE_TEST),
            'template' => $this->content->templateFor(self::TYPE_TEST),
            'category' => 'SYSTEM',
            'sync' => $sync,
            'skip_rate_limit' => false,
        ]);

        if (!$delivery) {
            throw new InvalidArgumentException('E-mail delivery is disabled (EMAIL_ENABLED=false).');
        }

        return $delivery;
    }

    // ================================================================= core

    /**
     * @param array<string, mixed> $context template context (already sanitised by the caller / resolver)
     * @param array{user?:User|null, idempotency_key?:string, subject?:string, template?:string, category?:string|null,
     *              notification_id?:string|null, sync?:bool, skip_rate_limit?:bool, dedupe_key?:string|null} $options
     * @throws InvalidArgumentException invalid recipient address
     */
    public function send(string $recipient, string $type, array $context, array $options = []): ?EmailDelivery
    {
        if (!$this->enabled()) {
            return null;
        }
        $recipient = $this->validateRecipient($recipient);
        /** @var User|null $user */
        $user = $options['user'] ?? null;
        $template = (string) ($options['template'] ?? $this->content->templateFor($type));
        $subject = (string) ($options['subject'] ?? $this->content->subjectFor($type));
        $key = Str::limit((string) ($options['idempotency_key'] ?? ($type . ':' . sha1($recipient . '|' . $subject . '|' . json_encode($context)))), 191, '');

        $existing = EmailDelivery::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $security = $this->isSecurity($type);
        if (empty($options['skip_rate_limit']) && $user && $this->rateLimited($user, $type, $security)) {
            $cancelled = $this->createRow($user, $type, $template, $recipient, $subject, $key, $options, EmailDelivery::CANCELLED, 'RATE_LIMITED', 'Per-user e-mail limit reached; in-app notification remains available.');
            Log::notice('EmailService: rate limit reached', ['type' => $type, 'user_id' => $user->id, 'recipient_domain' => EmailLogSanitizer::domain($recipient)]);

            return $cancelled;
        }

        $delivery = $this->createRow($user, $type, $template, $recipient, $subject, $key, $options, EmailDelivery::PENDING);
        if (!$delivery) {
            return EmailDelivery::query()->where('idempotency_key', $key)->first();
        }

        try {
            $cleanContext = $this->stripForbidden($context);
            if (!empty($options['sync'])) {
                try {
                    dispatch_sync(new SendFacultyLensEmailJob($delivery->id, $template, $subject, $cleanContext));
                } catch (Throwable $e) {
                    // the job already recorded the failure on the delivery row
                }
                $delivery->refresh();
            } else {
                $connection = (string) (config('email.queue.connection') ?: config('queue.default'));
                SendFacultyLensEmailJob::dispatch($delivery->id, $template, $subject, $cleanContext)
                    ->onConnection($connection)
                    ->onQueue((string) config('email.queue.name', 'emails'))
                    ->afterCommit();
                if (config("queue.connections.{$connection}.driver") === 'sync') {
                    $delivery->refresh(); // job already ran inline
                }
            }

            $this->audit->log('EMAIL_QUEUED', 'EmailDelivery', $delivery->id, [
                'type' => $type, 'template' => $template, 'recipient_domain' => EmailLogSanitizer::domain($recipient), 'queue' => (string) config('email.queue.name', 'emails'),
            ], $user);
        } catch (Throwable $e) {
            $classified = EmailErrorClassifier::classify($e);
            $delivery->forceFill([
                'status' => EmailDelivery::FAILED, 'failed_at' => now(),
                'error_code' => EmailErrorClassifier::QUEUE_ERROR, 'error_message' => $classified['message'],
            ])->save();
            Log::error('EmailService: queue dispatch failed', EmailLogSanitizer::context(['delivery_id' => $delivery->id, 'type' => $type, 'error' => get_class($e), 'detail' => $e->getMessage()]));
        }

        return $delivery;
    }

    // ============================================================== helpers

    /** Lowercased, trimmed and RFC-shaped; rejects header injection characters outright. */
    public function validateRecipient(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 190 || preg_match('/[\r\n\t\0]/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid recipient e-mail address is required.');
        }

        return $email;
    }

    /** Per-user hourly ceiling (separate ceiling for security e-mails) plus a short per-type burst window. */
    protected function rateLimited(User $user, string $type, bool $security): bool
    {
        $limit = (int) config($security ? 'email.rate_limit.security_per_user_per_hour' : 'email.rate_limit.per_user_per_hour', 30);
        if ($limit > 0) {
            $recent = EmailDelivery::query()->forUser($user)->where('created_at', '>=', now()->subHour())
                ->whereIn('status', [EmailDelivery::PENDING, EmailDelivery::PROCESSING, EmailDelivery::SENT])
                ->when(!$security, fn ($q) => $q->whereNotIn('category', array_merge((array) config('email.mandatory_categories', []), ['AUTH'])))
                ->count();
            if ($recent >= $limit) {
                return true;
            }
        }

        $window = (int) config('email.rate_limit.burst_window_seconds', 20);
        $burst = (int) config('email.rate_limit.burst_max_per_type', 5);
        if (!$security && $window > 0 && $burst > 0) {
            $count = EmailDelivery::query()->forUser($user)->where('type', $type)->where('created_at', '>=', now()->subSeconds($window))->count();
            if ($count >= $burst) {
                return true;
            }
        }

        return false;
    }

    protected function createRow(?User $user, string $type, string $template, string $recipient, string $subject, string $key, array $options, string $status, ?string $errorCode = null, ?string $errorMessage = null): ?EmailDelivery
    {
        try {
            return EmailDelivery::create([
                'user_id' => $user?->id,
                'notification_id' => $options['notification_id'] ?? null,
                'type' => Str::limit($type, 64, ''),
                'category' => isset($options['category']) ? Str::limit((string) $options['category'], 32, '') : null,
                'template' => Str::limit($template, 64, ''),
                'recipient' => $recipient,
                'subject' => Str::limit($subject, 255, ''),
                'status' => $status,
                'provider' => (string) config('mail.default', 'smtp'),
                'idempotency_key' => $key,
                'attempts' => 0,
                'queued_at' => $status === EmailDelivery::PENDING ? now() : null,
                'failed_at' => $status === EmailDelivery::FAILED ? now() : null,
                'error_code' => $errorCode,
                'error_message' => $errorMessage !== null ? EmailLogSanitizer::string($errorMessage) : null,
            ]);
        } catch (QueryException $e) {
            // unique(idempotency_key) race — the concurrent writer owns the delivery
            return null;
        }
    }

    /** @param array<string, mixed> $context */
    protected function stripForbidden(array $context): array
    {
        $forbidden = array_map('strtolower', (array) config('email.forbidden_context_keys', []));
        $clean = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            // reset_url legitimately carries the reset token; every other secret-like key is dropped
            if ($lower !== 'reset_url') {
                foreach ($forbidden as $f) {
                    if ($lower === $f || str_contains($lower, $f)) {
                        continue 2;
                    }
                }
            }
            $clean[$key] = is_array($value) ? $this->stripForbidden($value) : $value;
        }

        return $clean;
    }
}
