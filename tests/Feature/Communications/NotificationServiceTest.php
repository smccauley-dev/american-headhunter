<?php

namespace Tests\Feature\Communications;

use App\Models\Communications\Notification;
use App\Services\Communications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * In-app notification center (DB 7). Real rows on the communications connection
 * (tests run as owner → RLS bypassed; the RLS policy itself is covered by the
 * dedicated RLS test). Verifies create/unread-count/recent/mark-read behaviour.
 */
class NotificationServiceTest extends TestCase
{
    private string $userId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId      = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('communications')->table('notifications')
            ->whereIn('user_id', [$this->userId, $this->otherUserId])->delete();
        parent::tearDown();
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_notify_creates_an_unread_in_app_row(): void
    {
        $note = $this->service()->notify(
            userId: $this->userId,
            type: 'lease.early_termination_approved',
            title: 'Early termination approved',
            body: 'Your lease is now terminated.',
            actionUrl: '/member/leases/abc',
            data: ['lease_id' => 'abc'],
        );

        $this->assertNotNull($note);
        $this->assertSame('in_app', $note->channel);
        $this->assertNull($note->read_at);
        $this->assertNotNull($note->sent_at);
        $this->assertSame('abc', $note->data['lease_id']);
        $this->assertSame(1, $this->service()->unreadCount($this->userId));
    }

    public function test_unread_count_and_recent_are_scoped_to_the_user(): void
    {
        $this->service()->notify($this->userId, 'a', 'A', 'first');
        $this->service()->notify($this->userId, 'b', 'B', 'second');
        $this->service()->notify($this->otherUserId, 'c', 'C', 'theirs');

        $this->assertSame(2, $this->service()->unreadCount($this->userId));

        $recent = $this->service()->recentForUser($this->userId);
        $this->assertCount(2, $recent);
        // Most recent first.
        $this->assertSame('B', $recent[0]['title']);
        $this->assertFalse($recent[0]['read']);
    }

    public function test_mark_read_clears_one_and_scopes_to_owner(): void
    {
        $mine   = $this->service()->notify($this->userId, 'a', 'Mine', 'b');
        $theirs = $this->service()->notify($this->otherUserId, 'a', 'Theirs', 'b');

        $this->service()->markRead($this->userId, $mine->id);
        $this->assertSame(0, $this->service()->unreadCount($this->userId));

        // A user cannot mark someone else's notification read (scoped by user_id).
        $this->service()->markRead($this->userId, $theirs->id);
        $this->assertNull(Notification::find($theirs->id)->read_at);
    }

    public function test_recent_strips_non_relative_action_urls(): void
    {
        // System-authored today, but the front end navigates straight to
        // action_url — anything that isn't a same-origin relative path is dropped.
        DB::connection('communications')->table('notifications')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->userId, 'type' => 'a', 'channel' => 'in_app', 'title' => 'rel',  'body' => 'b', 'action_url' => '/member/leases/abc', 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'user_id' => $this->userId, 'type' => 'b', 'channel' => 'in_app', 'title' => 'abs',  'body' => 'b', 'action_url' => 'https://evil.example/phish', 'created_at' => now()->subSecond()],
            ['id' => (string) Str::uuid(), 'user_id' => $this->userId, 'type' => 'c', 'channel' => 'in_app', 'title' => 'pr',   'body' => 'b', 'action_url' => '//evil.example/phish', 'created_at' => now()->subSeconds(2)],
        ]);

        $byTitle = collect($this->service()->recentForUser($this->userId))->keyBy('title');

        $this->assertSame('/member/leases/abc', $byTitle['rel']['action_url']);
        $this->assertNull($byTitle['abs']['action_url']);
        $this->assertNull($byTitle['pr']['action_url']);
    }

    public function test_mark_all_read_clears_every_unread_for_the_user(): void
    {
        $this->service()->notify($this->userId, 'a', 'A', 'b');
        $this->service()->notify($this->userId, 'b', 'B', 'c');

        $this->service()->markAllRead($this->userId);

        $this->assertSame(0, $this->service()->unreadCount($this->userId));
    }
}
