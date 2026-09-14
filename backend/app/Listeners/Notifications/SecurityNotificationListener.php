<?php

namespace App\Listeners\Notifications;

use App\Events\SecurityAlertRaised;
use App\Events\SystemAlertRaised;
use App\Notifications\NotificationSeverity;
use App\Notifications\NotificationType;

/**
 * STEP 47: SECURITY / SYSTEM notifications. Mandatory (cannot be muted). Payloads carry no secrets, IPs,
 * stack traces or other technical detail — only what the faculty member needs to act on.
 */
class SecurityNotificationListener extends NotificationListener
{
    public function handleSecurityAlert(SecurityAlertRaised $event): void
    {
        $this->guard('security-alert', function () use ($event) {
            $this->notifications->notify($event->user, NotificationType::SECURITY_ALERT, [
                'title' => $event->title,
                'message' => $event->message,
                'severity' => NotificationSeverity::CRITICAL,
                'action_url' => $event->actionUrl ?? '/settings',
                'entity_type' => 'user',
                'entity_id' => $event->user->id,
                'dedupe_key' => $event->dedupeKey ?? (NotificationType::SECURITY_ALERT . ":user:{$event->user->id}:" . now()->timestamp),
                'data' => $event->data + ['action_label' => 'Review account security'],
            ]);
        });
    }

    public function handleSystemAlert(SystemAlertRaised $event): void
    {
        $this->guard('system-alert', function () use ($event) {
            $recipients = is_array($event->recipients) || $event->recipients instanceof \Traversable ? $event->recipients : [];
            $this->notifications->notifyMany($recipients, NotificationType::SYSTEM_ALERT, [
                'title' => $event->title,
                'message' => $event->message,
                'severity' => $event->severity,
                'dedupe_key' => $event->dedupeKey,
                'data' => $event->data,
            ]);
        });
    }
}
