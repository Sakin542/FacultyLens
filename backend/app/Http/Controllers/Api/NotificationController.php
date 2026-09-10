<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STEP 34: in-app notifications (Laravel database channel). Users only ever see their own.
 */
class NotificationController extends Controller
{
    /** GET /api/notifications?unread=1&per_page=20 */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $query = $request->boolean('unread') ? $request->user()->unreadNotifications() : $request->user()->notifications();
        $page = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Notifications retrieved.',
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ] + (array) $n->data)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /** POST /api/notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        if (!$notification) {
            return response()->json(['status' => 'error', 'message' => 'Notification not found.'], 404);
        }
        $notification->markAsRead();

        return response()->json(['status' => 'success', 'message' => 'Notification marked as read.', 'data' => null]);
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['status' => 'success', 'message' => 'All notifications marked as read.', 'data' => null]);
    }
}
