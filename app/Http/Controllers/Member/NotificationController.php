<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Services\Communications\NotificationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Member notification center — the full inbox plus mark-read actions. Reads and
 * mark-read run as the member (ah_runtime); RLS scopes every query to the
 * caller's own rows. Notifications are never created here — that is
 * system-authored (NotificationService, from jobs / db.system routes).
 */
class NotificationController extends Controller
{
    public function index(NotificationService $notifications): Response
    {
        $userId = session('auth.user_id');

        // Named `items` (not `notifications`) to avoid colliding with the
        // `notifications` Inertia shared prop the bell reads.
        return Inertia::render('Member/Notifications', [
            'items'        => $notifications->recentForUser($userId, 100),
            'unread_count' => $notifications->unreadCount($userId),
        ]);
    }

    public function markRead(string $notification, NotificationService $notifications): RedirectResponse
    {
        $notifications->markRead(session('auth.user_id'), $notification);

        return back();
    }

    public function markAllRead(NotificationService $notifications): RedirectResponse
    {
        $notifications->markAllRead(session('auth.user_id'));

        return back();
    }
}
