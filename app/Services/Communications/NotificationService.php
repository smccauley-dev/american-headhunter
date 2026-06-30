<?php

namespace App\Services\Communications;

use App\Models\Communications\Notification;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;

/**
 * In-app notification center (DB 7). Creates the "bell" notifications, exposes
 * the unread count + recent list for the member portal, and marks them read.
 *
 * Writes are system-authored: notify() inserts a row and must run under
 * ah_system (a queue job, or a member-write route wrapped in db.system such as
 * the early-termination decision). Reads/mark-read run as the member (ah_runtime)
 * with RLS scoping every query to the caller's own rows.
 *
 * notify() never throws — a notification failure must never break the
 * user-facing transaction that triggered it (mirrors AuditService's contract).
 */
class NotificationService extends BaseService
{
    /**
     * Create an in-app notification. System-authored — call from a job or a
     * db.system route. `$data` carries context ids ONLY (e.g. lease_id) for the
     * front-end to act on; never put PII or payment details there (it is
     * GIN-indexed and retained). Returns the row, or null if creation failed.
     */
    public function notify(
        string $userId,
        string $type,
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $data = [],
    ): ?Notification {
        try {
            return Notification::create([
                'user_id'    => $userId,
                'type'       => $type,
                'channel'    => 'in_app',
                'title'      => $title,
                'body'       => $body,
                'action_url' => $actionUrl,
                'data'       => $data,
                'sent_at'    => now(),  // in-app is delivered the moment it is written
            ]);
        } catch (\Throwable $e) {
            // Swallow — never bubble up into the triggering transaction.
            Log::warning('NotificationService::notify failed', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Count of the member's unread in-app notifications. */
    public function unreadCount(string $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->count();
    }

    /**
     * The member's most recent in-app notifications, shaped for the front-end.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentForUser(string $userId, int $limit = 30): array
    {
        return Notification::where('user_id', $userId)
            ->where('channel', 'in_app')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Notification $n) => $this->shape($n))
            ->all();
    }

    /** Mark one of the member's notifications read (no-op if not theirs/unknown). */
    public function markRead(string $userId, string $notificationId): void
    {
        Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Mark all of the member's unread notifications read. */
    public function markAllRead(string $userId): void
    {
        Notification::where('user_id', $userId)
            ->where('channel', 'in_app')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function shape(Notification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->title,
            'body'       => $n->body,
            'action_url' => $n->action_url,
            'read'       => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
