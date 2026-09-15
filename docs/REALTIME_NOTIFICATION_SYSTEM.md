# FacultyLens Real-Time Notification System (STEP 48)

> **Design Principle**: A notification is informational only. It never bypasses policies, approvals, or faculty autonomy.
> The notification system provides instantaneous, reactive updates across all faculty browser tabs without page refreshes.

---

## 1. Architecture Overview

```text
  Academic / Domain Operation (AI analysis, version approval, grading, etc.)
                          │
                          ▼
            Domain Event Dispatched (afterCommit)
                          │
                          ▼
             Domain Notification Listener
                          │
                          ▼
            NotificationService::notify()
                          │
                          ▼
          StoreNotificationJob (queued / async)
                          │
        ┌─────────────────┴─────────────────┐
        ▼                                   ▼
MySQL notifications table        NotificationCreated Broadcast Event
(Source of Truth + Audit)                   │ (ShouldBroadcastNow)
                                            ▼
                                  Laravel Reverb (WebSocket)
                                  Channel: private-users.{id}.notifications
                                            │
                                            ▼
                                   Laravel Echo Client
                                            │
                                  NotificationContext
                                            │
       ┌───────────────┬────────────────────┼───────────────────┐
       ▼               ▼                    ▼                   ▼
NotificationBell  NotificationToast  NotificationDropdown  /notifications
 (🔔 count badge)  (Instant popup)    (Live preview list)  (Management hub)
```

---

## 2. Backend Implementation

### A. Broadcast Event (`App\Events\NotificationCreated`)
- Implements `Illuminate\Contracts\Broadcasting\ShouldBroadcastNow` for low-latency WebSocket push without queue lag.
- Broadcasts on `PrivateChannel("users.{$userId}.notifications")`.
- Dispatches event name `NotificationCreated`.
- Serializes data via `toApi()`:
  - Exposes only UI-necessary attributes (`id`, `type`, `category`, `severity`, `title`, `message`, `data`, `action_url`, `created_at`, `read_at`).
  - Strips all secrets, API tokens, and internal database foreign keys.

### B. Channel Authorization (`routes/channels.php`)
- Strict IDOR authorization:
  ```php
  Broadcast::channel('users.{userId}.notifications', function ($user, $userId) {
      return (int) $user->id === (int) $userId;
  });
  ```
- Rejects any unauthorized user with `403 Forbidden`.
- Accessible via `/api/broadcasting/auth` with Laravel Sanctum Bearer token headers.

### C. Safe Broadcasting Dispatcher (`NotificationService.php`)
- Broadcast dispatch is wrapped in a fail-safe `try/catch` block:
  ```php
  try {
      broadcast(new NotificationCreated($notification));
  } catch (\Throwable $e) {
      Log::warning('Real-time notification broadcast failed', [
          'notification_id' => $notification->id,
          'error' => $e->getMessage(),
      ]);
  }
  ```
- **Resilience Guarantee:** A WebSocket or network glitch will NEVER disrupt notification persistence, transaction commits, or academic operations.

---

## 3. Frontend Architecture

### A. Laravel Echo Singleton (`services/echo.ts`)
- Configured with `pusher-js` engine pointed at Laravel Reverb:
  - `wsHost`: `import.meta.env.VITE_REVERB_HOST`
  - `wsPort`: `import.meta.env.VITE_REVERB_PORT`
  - `authEndpoint`: `/api/broadcasting/auth` with Sanctum Bearer authorization.
- Provides `subscribeToUserNotifications(userId, onNotification, onConnectionChange)` with automatic cleanup and reconnection tracking.

### B. Reactive State Provider (`context/NotificationContext.tsx`)
- Maintains centralized reactive state:
  - `unreadCount`: current number of unread notifications.
  - `notifications`: cached recent notifications for the active session.
  - `toasts`: queue of up to 4 non-intrusive notification toasts.
  - `connected`: active connection status (`connecting`, `connected`, `disconnected`, `unavailable`).
- **Optimistic UI Updates:**
  - Marking a single notification as read decrements the badge immediately (`🔔 4` $\rightarrow$ `🔔 3`).
  - Marking all notifications as read resets badge immediately (`🔔 3` $\rightarrow$ `🔔 0`).
  - Authoritative counts from API responses reconcile state gracefully.
- **Multi-Tab Sync:**
  - Uses browser `BroadcastChannel("facultylens:notifications")` to sync badge counts and reads across all open tabs.
- **Deduplication:**
  - Strict ID checks prevent repeated notification popups or double increments if events are replayed.
- **Auto-Resynchronization:**
  - On WebSocket reconnection after network loss, automatically queries `/api/notifications/unread-count` to catch up on any missed notifications.

### C. UI Components
- `NotificationBell`: Dynamic bell icon displaying numeric unread badge.
- `NotificationBadge`: Accessible count badge (`sr-only` description, tabular numerals, hides at 0).
- `NotificationDropdown`: Dropdown sheet showing recent items, mark-all-read button, and link to settings.
- `NotificationToastContainer` & `NotificationToast`: Non-intrusive floating alerts styled with FacultyLens sage/white palette, auto-dismiss timer, and keyboard accessibility.
- `Notifications` Page: Complete notification center with server-side filters, bulk actions, and pagination.

---

## 4. Polling Fallback

When real-time WebSocket connection is active (`connected`), background polling is paused to reduce server and database load.
If WebSocket connection drops or cannot be established (e.g. strict firewall blocking WebSockets), the frontend automatically falls back to lightweight polling (`45s` interval, paused when document is hidden) to ensure complete reliability.

