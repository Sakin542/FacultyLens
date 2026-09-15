<?php

namespace App\Services\Notification;

use App\Jobs\StoreNotificationJob;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationCategory;
use App\Notifications\NotificationSeverity;
use App\Notifications\NotificationType;
use App\Services\AuditLogService;
use App\Services\Email\EmailService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * STEP 47: the ONLY place FacultyLens creates, reads or mutates in-app notifications.
 *
 *   domain event → listener → notify() → [preference check, validation, dedupe key] → StoreNotificationJob (queue)
 *                → store() (idempotent insert, unique(user_id, dedupe_key)) → notifications table → API → React.
 *
 * Guarantees: a notification never carries secrets or private academic content, never goes to a recipient the
 * caller did not resolve through the authorization model, never duplicates on retry, and never makes the
 * underlying academic operation fail (dispatch errors are logged and swallowed).
 *
 * E-mail is a second, independent channel: notify() hands the same validated payload to EmailService, which applies
 * the recipient's e-mail preference and queues SendFacultyLensEmailJob. An e-mail problem never affects the in-app
 * write and vice versa.
 */
class NotificationService
{
    public const MAX_TITLE = 255;
    public const MAX_MESSAGE = 2000;
    public const MAX_DATA_STRING = 500;
    public const MAX_DATA_KEYS = 25;

    public function __construct(protected AuditLogService $audit, protected EmailService $emails) {}

    // =============================================================== create

    /**
     * Queue (or write inline on a sync queue) one notification for one recipient.
     *
     * @param array{title:string, message:string, severity?:string, data?:array, action_url?:string|null, entity_type?:string|null,
     *              entity_id?:int|null, dedupe_key?:string|null, expires_at?:CarbonInterface|string|null} $attributes
     * @param array{queue?:bool, actor_id?:int|null, email?:bool} $options
     * @return Notification|null the stored row when written inline, null when queued / skipped
     */
    public function notify(User|int $recipient, string $type, array $attributes, array $options = []): ?Notification
    {
        $userId = $recipient instanceof User ? (int) $recipient->id : (int) $recipient;
        if ($userId <= 0) {
            throw new InvalidArgumentException('Notification recipient is required.');
        }
        if (!($recipient instanceof User) && !User::whereKey($userId)->exists()) {
            throw new InvalidArgumentException("Notification recipient {$userId} does not exist.");
        }

        $payload = $this->buildPayload($userId, $type, $attributes, $options);

        $stored = null;
        if (NotificationPreference::inAppEnabled($userId, $type)) {
            try {
                $queue = $options['queue'] ?? (bool) config('notifications.queue.enabled', true);
                if (!$queue) {
                    $stored = $this->store($payload);
                } else {
                    StoreNotificationJob::dispatch($payload)
                        ->onConnection(config('notifications.queue.connection') ?: config('queue.default'))
                        ->onQueue((string) config('notifications.queue.name', 'default'))
                        // events fire inside domain transactions; never persist a notification for a rolled-back operation
                        ->afterCommit();
                    $stored = $this->isSyncQueue() ? $this->findByDedupe($userId, $payload['dedupe_key']) : null;
                }
            } catch (Throwable $e) {
                // Delivery problems are never allowed to fail the academic operation that produced the event.
                Log::warning('NotificationService: dispatch failed', ['type' => $type, 'user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        // E-mail channel: decided and queued independently of the in-app channel (its own preference, its own job,
        // never throws). Runs after the in-app dispatch so an inline/sync write can be linked from the delivery row.
        if ($options['email'] ?? true) {
            $this->emails->queueNotificationEmail($payload, $recipient instanceof User ? $recipient : null);
        }

        return $stored;
    }

    /**
     * @param iterable<User|int> $recipients
     * @return int number of recipients accepted for delivery
     */
    public function notifyMany(iterable $recipients, string $type, array $attributes, array $options = []): int
    {
        $count = 0;
        $seen = [];
        foreach ($recipients as $recipient) {
            $id = $recipient instanceof User ? (int) $recipient->id : (int) $recipient;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $this->notify($recipient, $type, $attributes, $options);
                $count++;
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::warning('NotificationService: notifyMany skipped a recipient', ['type' => $type, 'user_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /**
     * Idempotent persistence — executed by StoreNotificationJob (possibly several times on retry).
     * unique(user_id, dedupe_key) makes a duplicate insert impossible; a race simply returns the existing row.
     */
    public function store(array $payload): ?Notification
    {
        $existing = $this->findByDedupe((int) $payload['user_id'], $payload['dedupe_key'] ?? null);
        if ($existing) {
            return $existing;
        }

        try {
            $notification = Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $payload['user_id'],
                'notifiable_type' => User::class,
                'notifiable_id' => $payload['user_id'],
                'type' => $payload['type'],
                'category' => $payload['category'],
                'severity' => $payload['severity'],
                'title' => $payload['title'],
                'message' => $payload['message'],
                'data' => $payload['data'],
                'action_url' => $payload['action_url'],
                'entity_type' => $payload['entity_type'],
                'entity_id' => $payload['entity_id'],
                'dedupe_key' => $payload['dedupe_key'],
                'expires_at' => $payload['expires_at'],
                'read_at' => null,
                'dismissed_at' => null,
            ]);
        } catch (QueryException $e) {
            $existing = $this->findByDedupe((int) $payload['user_id'], $payload['dedupe_key'] ?? null);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        $this->audit->log('NOTIFICATION_CREATED', 'Notification', null, [
            'notification_id' => $notification->id,
            'recipient_id' => $notification->user_id,
            'type' => $notification->type,
            'category' => $notification->category,
            'severity' => $notification->severity,
            'entity_type' => $notification->entity_type,
            'entity_id' => $notification->entity_id,
            'actor_id' => $payload['actor_id'] ?? null,
        ], isset($payload['actor_id']) ? User::find($payload['actor_id']) : null);

        // Real-time broadcast: deliver to the user's private channel
        // Wrapped in try/catch so a WebSocket / broadcast error never fails the database persistence or academic operation
        try {
            broadcast(new \App\Events\NotificationCreated($notification));
        } catch (Throwable $e) {
            Log::warning('NotificationService: real-time broadcast failed', [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }

    // ================================================================= read

    /**
     * @param array{filter?:string|null, unread?:bool, category?:string|null, from?:string|null, to?:string|null} $filters
     */
    public function getNotifications(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $max = (int) config('notifications.pagination.max_per_page', 50);
        $perPage = min(max($perPage, 1), $max);

        $query = Notification::query()->forUser($user)->active()
            ->select(['id', 'user_id', 'type', 'category', 'severity', 'title', 'message', 'data', 'action_url', 'entity_type', 'entity_id', 'read_at', 'dismissed_at', 'expires_at', 'created_at']);

        $filter = strtoupper((string) ($filters['filter'] ?? 'ALL'));
        if ($filter === 'UNREAD' || !empty($filters['unread'])) {
            $query->whereNull('read_at');
        }
        if ($filter !== 'ALL' && $filter !== 'UNREAD' && NotificationCategory::isValid($filter)) {
            $query->category($filter);
        }
        if (!empty($filters['category']) && NotificationCategory::isValid(strtoupper($filters['category']))) {
            $query->category($filters['category']);
        }
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function getUnreadCount(User $user): int
    {
        return Notification::query()->forUser($user)->active()->whereNull('read_at')->count();
    }

    /** Ownership-scoped lookup: another user's id yields null (→ 404), never the row. */
    public function findForUser(User $user, string $id): ?Notification
    {
        if (!Str::isUuid($id)) {
            return null;
        }

        return Notification::query()->forUser($user)->whereKey($id)->first();
    }

    // ============================================================== mutate

    public function markAsRead(User $user, Notification $notification): Notification
    {
        $this->assertOwner($user, $notification);
        if ($notification->isUnread()) {
            $notification->markAsRead();
            $this->audit->log('NOTIFICATION_READ', 'Notification', null, $this->auditMeta($notification), $user);
        }

        return $notification;
    }

    public function markAllAsRead(User $user): int
    {
        $count = Notification::query()->forUser($user)->active()->whereNull('read_at')->update(['read_at' => now()]);
        if ($count > 0) {
            $this->audit->log('NOTIFICATION_READ', 'Notification', null, ['scope' => 'all', 'count' => $count], $user);
        }

        return $count;
    }

    public function dismiss(User $user, Notification $notification): Notification
    {
        $this->assertOwner($user, $notification);
        if (!$notification->isDismissed()) {
            $notification->forceFill(['dismissed_at' => now(), 'read_at' => $notification->read_at ?? now()])->save();
            $this->audit->log('NOTIFICATION_DISMISSED', 'Notification', null, $this->auditMeta($notification), $user);
        }

        return $notification;
    }

    public function delete(User $user, Notification $notification): void
    {
        $this->assertOwner($user, $notification);
        $meta = $this->auditMeta($notification);
        $notification->delete();
        $this->audit->log('NOTIFICATION_DELETED', 'Notification', null, $meta, $user);
    }

    public function recordViewed(User $user, Notification $notification): void
    {
        $this->assertOwner($user, $notification);
        $this->audit->log('NOTIFICATION_VIEWED', 'Notification', null, $this->auditMeta($notification), $user);
    }

    /**
     * Scheduled retention: removes expired rows and stale read/dismissed rows (config notifications.retention).
     * Audit rows are untouched.
     */
    public function purge(): int
    {
        $deleted = Notification::query()->whereNotNull('expires_at')->where('expires_at', '<', now()->subDay())->delete();

        $readDays = (int) config('notifications.retention.read_retention_days', 0);
        if ($readDays > 0) {
            $deleted += Notification::query()
                ->where(fn ($q) => $q->whereNotNull('read_at')->orWhereNotNull('dismissed_at'))
                ->where('created_at', '<', now()->subDays($readDays))->delete();
        }
        $maxDays = (int) config('notifications.retention.max_retention_days', 0);
        if ($maxDays > 0) {
            $deleted += Notification::query()->where('created_at', '<', now()->subDays($maxDays))->delete();
        }

        return (int) $deleted;
    }

    // ============================================================== helpers

    /**
     * Normalise + validate everything before it touches the queue, so a bad producer fails loudly in tests.
     */
    public function buildPayload(int $userId, string $type, array $attributes, array $options = []): array
    {
        if (!NotificationType::isValid($type)) {
            throw new InvalidArgumentException("Unknown notification type [{$type}].");
        }
        $category = NotificationType::category($type);
        if (!$category || !NotificationCategory::isValid($category)) {
            throw new InvalidArgumentException("Notification type [{$type}] has no valid category.");
        }
        $severity = strtoupper((string) ($attributes['severity'] ?? NotificationType::defaultSeverity($type)));
        if (!NotificationSeverity::isValid($severity)) {
            throw new InvalidArgumentException("Invalid notification severity [{$severity}].");
        }
        $title = trim((string) ($attributes['title'] ?? ''));
        $message = trim((string) ($attributes['message'] ?? ''));
        if ($title === '' || $message === '') {
            throw new InvalidArgumentException('Notification title and message are required.');
        }

        $entityType = isset($attributes['entity_type']) ? Str::limit(Str::snake((string) $attributes['entity_type']), 64, '') : null;
        $entityId = isset($attributes['entity_id']) && is_numeric($attributes['entity_id']) ? (int) $attributes['entity_id'] : null;
        $dedupe = $attributes['dedupe_key'] ?? null;
        if ($dedupe === null && $entityType !== null && $entityId !== null) {
            $dedupe = "{$type}:{$entityType}:{$entityId}";
        }
        $dedupe = $dedupe !== null ? Str::limit((string) $dedupe, 191, '') : null;

        $expires = $attributes['expires_at'] ?? null;
        if ($expires === null) {
            $days = (int) config("notifications.retention.default_expiry_days.{$type}", 0);
            $expires = $days > 0 ? now()->addDays($days) : null;
        }

        return [
            'user_id' => $userId,
            'type' => $type,
            'category' => $category,
            'severity' => $severity,
            'title' => Str::limit($title, self::MAX_TITLE, ''),
            'message' => Str::limit($message, self::MAX_MESSAGE, ''),
            'data' => $this->sanitizeData((array) ($attributes['data'] ?? [])),
            'action_url' => $this->sanitizeActionUrl($attributes['action_url'] ?? null),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'dedupe_key' => $dedupe,
            'expires_at' => $expires instanceof CarbonInterface ? $expires->toDateTimeString() : $expires,
            'actor_id' => isset($options['actor_id']) ? (int) $options['actor_id'] : null,
        ];
    }

    /**
     * Only scalars / one level of scalar arrays, short strings, and no forbidden keys (secrets, student text, prompts).
     */
    public function sanitizeData(array $data, int $depth = 0): array
    {
        $forbidden = array_map('strtolower', (array) config('notifications.forbidden_data_keys', []));
        $clean = [];
        foreach ($data as $key => $value) {
            if (count($clean) >= self::MAX_DATA_KEYS) {
                break;
            }
            $lower = strtolower((string) $key);
            foreach ($forbidden as $f) {
                if ($lower === $f || str_contains($lower, $f)) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                if ($depth >= 1) {
                    continue;
                }
                $clean[$key] = $this->sanitizeData($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, self::MAX_DATA_STRING, '');
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** Relative app paths only (or the configured frontend origin); anything else is dropped. */
    protected function sanitizeActionUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return Str::limit($url, 1024, '');
        }
        $frontend = rtrim((string) config('notifications.frontend_url'), '/');
        if ($frontend !== '' && str_starts_with($url, $frontend . '/')) {
            return Str::limit(substr($url, strlen($frontend)), 1024, '');
        }

        return null;
    }

    protected function findByDedupe(int $userId, ?string $dedupe): ?Notification
    {
        if ($dedupe === null) {
            return null;
        }

        return Notification::query()->where('user_id', $userId)->where('dedupe_key', $dedupe)->first();
    }

    protected function isSyncQueue(): bool
    {
        $connection = config('notifications.queue.connection') ?: config('queue.default');

        return config("queue.connections.{$connection}.driver") === 'sync';
    }

    protected function assertOwner(User $user, Notification $notification): void
    {
        if ((int) $notification->user_id !== (int) $user->id) {
            abort(404, 'Notification not found.');
        }
    }

    protected function auditMeta(Notification $notification): array
    {
        return [
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'category' => $notification->category,
            'entity_type' => $notification->entity_type,
            'entity_id' => $notification->entity_id,
        ];
    }
}
