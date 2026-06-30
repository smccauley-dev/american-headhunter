<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Communications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile/token API for the member notification center — list, unread badge, and
 * mark-read. Runs as the Sanctum-authenticated member (ah_runtime); RLS and the
 * service both scope every query to that user. Notifications are never created
 * here — creation is system-authored (NotificationService, from jobs / db.system).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** GET /api/v1/notifications — paginated in-app notifications. */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $page   = $this->notifications->paginateForUser($userId, (int) $request->integer('per_page', 20));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'unread_count' => $this->notifications->unreadCount($userId),
            ],
        ]);
    }

    /** GET /api/v1/notifications/unread-count — just the badge number. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->notifications->unreadCount($request->user()->id),
        ]);
    }

    /** POST /api/v1/notifications/{notification}/read — mark one read. */
    public function markRead(string $notification, Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $this->notifications->markRead($userId, $notification);

        return response()->json(['unread_count' => $this->notifications->unreadCount($userId)]);
    }

    /** POST /api/v1/notifications/read-all — mark every unread read. */
    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $this->notifications->markAllRead($userId);

        return response()->json(['unread_count' => $this->notifications->unreadCount($userId)]);
    }
}
