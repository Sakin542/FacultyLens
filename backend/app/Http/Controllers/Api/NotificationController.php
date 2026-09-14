<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\NotificationCategory;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 47: notification center API. Every lookup is scoped to the authenticated user inside NotificationService
 * (another user's id → 404, never the row). A notification grants no access: action URLs are re-authorized by
 * the pages they open.
 */
class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    /** GET /api/notifications?filter=all|unread|<CATEGORY>&page=1&per_page=20[&from=&to=] */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'in:' . implode(',', NotificationCategory::all())],
            'unread' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('notifications.pagination.max_per_page', 50)],
        ]);

        $user = $request->user();
        $page = $this->notifications->getNotifications($user, [
            'filter' => $validated['filter'] ?? null,
            'category' => $validated['category'] ?? null,
            'unread' => $request->boolean('unread'),
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ], (int) ($validated['per_page'] ?? config('notifications.pagination.default_per_page', 20)));

        return response()->json([
            'status' => 'success',
            'message' => 'Notifications retrieved.',
            'data' => collect($page->items())->map(fn ($n) => $n->toApi())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $this->notifications->getUnreadCount($user),
                'poll_interval_seconds' => (int) config('notifications.poll_interval_seconds', 45),
            ],
        ]);
    }

    /** GET /api/notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Unread count retrieved.',
            'data' => [
                'unread_count' => $this->notifications->getUnreadCount($request->user()),
                'poll_interval_seconds' => (int) config('notifications.poll_interval_seconds', 45),
            ],
        ]);
    }

    /** GET /api/notifications/{notification} */
    public function show(Request $request, string $notification): JsonResponse
    {
        $row = $this->notifications->findForUser($request->user(), $notification);
        if (!$row) {
            return $this->notFound();
        }
        $this->notifications->recordViewed($request->user(), $row);

        return response()->json(['status' => 'success', 'message' => 'Notification retrieved.', 'data' => $row->toApi()]);
    }

    /** POST /api/notifications/{notification}/read */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $this->notifications->findForUser($request->user(), $notification);
        if (!$row) {
            return $this->notFound();
        }
        $row = $this->notifications->markAsRead($request->user(), $row);

        return response()->json(['status' => 'success', 'message' => 'Notification marked as read.', 'data' => $row->toApi(), 'meta' => ['unread_count' => $this->notifications->getUnreadCount($request->user())]]);
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllAsRead($request->user());

        return response()->json(['status' => 'success', 'message' => 'All notifications marked as read.', 'data' => ['updated' => $count], 'meta' => ['unread_count' => 0]]);
    }

    /** POST /api/notifications/{notification}/dismiss */
    public function dismiss(Request $request, string $notification): JsonResponse
    {
        $row = $this->notifications->findForUser($request->user(), $notification);
        if (!$row) {
            return $this->notFound();
        }
        $row = $this->notifications->dismiss($request->user(), $row);

        return response()->json(['status' => 'success', 'message' => 'Notification dismissed.', 'data' => $row->toApi(), 'meta' => ['unread_count' => $this->notifications->getUnreadCount($request->user())]]);
    }

    /** DELETE /api/notifications/{notification} */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $row = $this->notifications->findForUser($request->user(), $notification);
        if (!$row) {
            return $this->notFound();
        }
        $this->notifications->delete($request->user(), $row);

        return response()->json(['status' => 'success', 'message' => 'Notification deleted.', 'data' => null, 'meta' => ['unread_count' => $this->notifications->getUnreadCount($request->user())]]);
    }

    protected function notFound(): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => 'Notification not found.'], 404);
    }
}
