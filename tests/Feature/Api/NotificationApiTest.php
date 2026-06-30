<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mobile notification API — GET /api/v1/notifications(/unread-count) and
 * POST .../{id}/read, .../read-all. Token-authenticated parity with the member
 * portal "bell".
 *
 * Every query is scoped to the Sanctum user in the service layer (and by RLS in
 * production under ah_runtime; the DB-level enforcement is proved separately in
 * NotificationRlsWriteTest). Here the focus is the HTTP contract + that one
 * member can never see or mark another member's notifications.
 */
class NotificationApiTest extends TestCase
{
    private string $userId;

    private string $otherUserId;

    private string $bearerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();

        $password = 'NotifyTest123!';

        DB::connection('identity')->table('users')->insert([
            'id' => $this->userId,
            'email' => "notify-{$this->userId}@example.com",
            'password_hash' => Hash::make($password),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userId,
            'first_name' => 'Notify',
            'last_name' => 'Tester',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => "notify-{$this->userId}@example.com",
            'password' => $password,
        ]);
        $this->bearerToken = $login->json('token');
    }

    protected function tearDown(): void
    {
        DB::connection('communications')->table('notifications')
            ->whereIn('user_id', [$this->userId, $this->otherUserId])->delete();

        DB::connection('identity')->table('personal_access_tokens')->where('tokenable_id', $this->userId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        foreach (['identity', 'communications'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    /** Seed an in-app notification directly (system-authored). Returns its id. */
    private function seedNote(string $userId, string $title, ?string $actionUrl = null, bool $read = false): string
    {
        $id = (string) Str::uuid();
        DB::connection('communications')->table('notifications')->insert([
            'id' => $id,
            'user_id' => $userId,
            'type' => 'lease.early_termination_approved',
            'channel' => 'in_app',
            'title' => $title,
            'body' => 'body',
            'action_url' => $actionUrl,
            'read_at' => $read ? now() : null,
            'created_at' => now(),
        ]);

        return $id;
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_unread_count_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
    }

    // ── List ────────────────────────────────────────────────────────────────────

    public function test_index_returns_only_the_callers_notifications(): void
    {
        $this->seedNote($this->userId, 'Mine A', '/member/leases/abc');
        $this->seedNote($this->userId, 'Mine B');
        $this->seedNote($this->otherUserId, 'Theirs');

        $response = $this->withToken($this->bearerToken)->getJson('/api/v1/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['id', 'type', 'title', 'body', 'action_url', 'read', 'created_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'unread_count'],
        ]);
        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('meta.unread_count', 2);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine A'));
        $this->assertTrue($titles->contains('Mine B'));
        $this->assertFalse($titles->contains('Theirs'));
    }

    public function test_index_strips_non_relative_action_urls(): void
    {
        $this->seedNote($this->userId, 'evil', 'https://evil.example/phish');

        $response = $this->withToken($this->bearerToken)->getJson('/api/v1/notifications');

        $response->assertJsonPath('data.0.action_url', null);
    }

    public function test_index_clamps_per_page(): void
    {
        $response = $this->withToken($this->bearerToken)
            ->getJson('/api/v1/notifications?per_page=999');

        // Service clamps to 50.
        $response->assertJsonPath('meta.per_page', 50);
    }

    // ── Unread count ────────────────────────────────────────────────────────────

    public function test_unread_count_counts_only_unread_for_the_caller(): void
    {
        $this->seedNote($this->userId, 'unread 1');
        $this->seedNote($this->userId, 'read 1', null, read: true);
        $this->seedNote($this->otherUserId, 'theirs unread');

        $this->withToken($this->bearerToken)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 1);
    }

    // ── Mark read ───────────────────────────────────────────────────────────────

    public function test_mark_read_clears_one_and_returns_new_count(): void
    {
        $mine = $this->seedNote($this->userId, 'a');
        $this->seedNote($this->userId, 'b');

        $this->withToken($this->bearerToken)
            ->postJson("/api/v1/notifications/{$mine}/read")
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 1);

        $this->assertNotNull(
            DB::connection('communications')->table('notifications')->where('id', $mine)->value('read_at')
        );
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $theirs = $this->seedNote($this->otherUserId, 'theirs');

        $this->withToken($this->bearerToken)
            ->postJson("/api/v1/notifications/{$theirs}/read")
            ->assertStatus(200);

        // Untouched — scoped to the caller's user_id.
        $this->assertNull(
            DB::connection('communications')->table('notifications')->where('id', $theirs)->value('read_at')
        );
    }

    public function test_mark_all_read_clears_every_unread_for_the_caller(): void
    {
        $this->seedNote($this->userId, 'a');
        $this->seedNote($this->userId, 'b');
        $theirs = $this->seedNote($this->otherUserId, 'theirs');

        $this->withToken($this->bearerToken)
            ->postJson('/api/v1/notifications/read-all')
            ->assertStatus(200)
            ->assertJsonPath('unread_count', 0);

        // The other user's notification stays unread.
        $this->assertNull(
            DB::connection('communications')->table('notifications')->where('id', $theirs)->value('read_at')
        );
    }
}
