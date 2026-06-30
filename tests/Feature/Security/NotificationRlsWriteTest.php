<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * notifications (DB 7) is system-authored, owner-read, owner-mark-read.
 *
 * RLS is enabled with a FOR SELECT policy (own rows + staff) and a FOR UPDATE
 * policy scoped to the owner (so a member may mark THEIR OWN rows read), and NO
 * INSERT/DELETE policy — creation and pruning are system-authored (ah_system).
 * The UPDATE policy's WITH CHECK pins user_id so a row can never be reassigned to
 * another user.
 *
 * Connects EXPLICITLY as ah_runtime and proves:
 *   1. RLS is enabled
 *   2. the owner may READ their own notification
 *   3. an unrelated user may NOT read it
 *   4. staff may READ any notification
 *   5. the owner may UPDATE (mark read) their own row
 *   6. an unrelated user may NOT update it (0 affected)
 *   7. nobody may forge (INSERT) a notification (no write policy → denied)
 *   8. the owner may NOT reassign a row to another user (WITH CHECK denies it)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class NotificationRlsWriteTest extends TestCase
{
    private const RUNTIME = 'communications_rls_write_test';

    private string $ownerId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.communications');
        if (! $base) {
            $this->markTestSkipped('communications connection not configured.');
        }
        config(['database.connections.' . self::RUNTIME => array_merge($base, [
            'username' => env('DB_COMMUNICATIONS_USERNAME', 'ah_runtime'),
            'password' => env('DB_COMMUNICATIONS_PASSWORD', 'secret'),
        ])]);

        try {
            DB::connection(self::RUNTIME)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_runtime Postgres connection unavailable: ' . $e->getMessage());
        }

        $this->ownerId     = (string) Str::uuid();
        $this->otherUserId = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        DB::connection('communications')->table('notifications')
            ->whereIn('user_id', [$this->ownerId, $this->otherUserId])->delete();
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    private function notificationRow(?string $userId = null): array
    {
        return [
            'id'      => (string) Str::uuid(),
            'user_id' => $userId ?? $this->ownerId,
            'type'    => 'lease.early_termination_approved',
            'channel' => 'in_app',
            'title'   => 'Approved',
            'body'    => 'Your lease is now terminated.',
        ];
    }

    private function seedNotification(): string
    {
        $row = $this->notificationRow();
        DB::connection('communications')->table('notifications')->insert($row);

        return $row['id'];
    }

    public function test_rls_is_enabled_on_notifications(): void
    {
        $row = DB::connection('communications')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'notifications'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on notifications.');
    }

    public function test_owner_can_read_own_notification(): void
    {
        $this->seedNotification();
        $this->setContext($this->ownerId, '');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('notifications')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_unrelated_user_cannot_read_notification(): void
    {
        $this->seedNotification();
        $this->setContext($this->otherUserId, '');

        $this->assertSame(0, DB::connection(self::RUNTIME)->table('notifications')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_staff_can_read_any_notification(): void
    {
        $this->seedNotification();
        $this->setContext($this->otherUserId, 'staff');

        $this->assertSame(1, DB::connection(self::RUNTIME)->table('notifications')
            ->where('user_id', $this->ownerId)->count());
    }

    public function test_owner_can_mark_own_notification_read(): void
    {
        $id = $this->seedNotification();
        $this->setContext($this->ownerId, '');

        $affected = DB::connection(self::RUNTIME)->table('notifications')
            ->where('id', $id)->update(['read_at' => now()]);

        $this->assertSame(1, $affected, 'A member must be able to mark their own notification read.');
    }

    public function test_unrelated_user_cannot_mark_notification_read(): void
    {
        $id = $this->seedNotification();
        $this->setContext($this->otherUserId, '');

        $affected = DB::connection(self::RUNTIME)->table('notifications')
            ->where('id', $id)->update(['read_at' => now()]);

        $this->assertSame(0, $affected, 'A member must not touch another user\'s notification.');
    }

    public function test_runtime_cannot_insert_notification(): void
    {
        $this->setContext($this->ownerId, '');

        $this->expectException(QueryException::class);

        // No write policy — RLS default-denies the INSERT even for one's own user_id.
        DB::connection(self::RUNTIME)->table('notifications')->insert($this->notificationRow());
    }

    public function test_owner_cannot_reassign_notification_to_another_user(): void
    {
        $id = $this->seedNotification();
        $this->setContext($this->ownerId, '');

        // WITH CHECK pins user_id — reassigning the row to someone else is rejected.
        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('notifications')
            ->where('id', $id)->update(['user_id' => $this->otherUserId]);
    }
}
