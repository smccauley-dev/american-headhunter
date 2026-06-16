<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-045 regression — field check-in/out writes are permitted (and
 * correctly scoped) under the non-owner ah_runtime role.
 *
 * The defect: check_ins had only a FOR SELECT policy. With RLS enabled and no
 * permissive write policy, PostgreSQL default-denies every INSERT/UPDATE for a
 * non-owner role. This was invisible while the app connected as the owner
 * (ah_app, bypasses RLS); SEC-043's flip to ah_runtime made member check-in
 * (INSERT) and check-out (UPDATE) silently fail.
 *
 * The fix adds self-service write policies (check_ins_insert_self /
 * check_ins_update_self). This test connects EXPLICITLY as ah_runtime and
 * proves:
 *
 *   1. a hunter may INSERT their own check-in
 *   2. a hunter may UPDATE (check out) their own row
 *   3. a hunter may NOT INSERT a row attributed to another user (WITH CHECK)
 *   4. a hunter may NOT UPDATE another user's row (USING filters it out)
 *   5. staff may INSERT on a user's behalf (support override)
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class CheckInRlsWriteTest extends TestCase
{
    private const RUNTIME = 'lease_rls_write_test';

    private string $leaseId;
    private string $applicationId;
    private string $lesseeId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.lease');
        if (! $base) {
            $this->markTestSkipped('lease connection not configured.');
        }
        config(['database.connections.' . self::RUNTIME => array_merge($base, [
            'username' => env('DB_LEASE_USERNAME', 'ah_runtime'),
            'password' => env('DB_LEASE_PASSWORD', 'secret'),
        ])]);

        try {
            DB::connection(self::RUNTIME)->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_runtime Postgres connection unavailable: ' . $e->getMessage());
        }

        $this->lesseeId      = (string) Str::uuid();
        $this->otherUserId   = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        // Fixtures written via the OWNER connection (bypasses RLS on write).
        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => 'active',
            'start_date'     => '2026-01-01',
            'end_date'       => '2026-12-31',
            'total_price'    => 1000.00,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->leaseId)) {
            // check_ins cascade-delete with the parent lease (ON DELETE CASCADE).
            DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        }
        if (isset($this->applicationId)) {
            DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        }
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    /** Apply an RLS context to the runtime connection's session. */
    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    /** Seed a check-in row directly (owner connection, bypasses RLS). */
    private function seedCheckIn(string $userId): string
    {
        $id = (string) Str::uuid();
        DB::connection('lease')->table('check_ins')->insert([
            'id'            => $id,
            'lease_id'      => $this->leaseId,
            'user_id'       => $userId,
            'checked_in_at' => now(),
        ]);

        return $id;
    }

    public function test_rls_is_enabled_on_check_ins(): void
    {
        $row = DB::connection('lease')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'check_ins'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on check_ins.');
    }

    public function test_hunter_can_insert_own_check_in(): void
    {
        $this->setContext($this->lesseeId, '');

        DB::connection(self::RUNTIME)->table('check_ins')->insert([
            'id'            => (string) Str::uuid(),
            'lease_id'      => $this->leaseId,
            'user_id'       => $this->lesseeId,
            'checked_in_at' => now(),
        ]);

        $this->assertSame(1, DB::connection('lease')->table('check_ins')
            ->where('lease_id', $this->leaseId)->where('user_id', $this->lesseeId)->count());
    }

    public function test_hunter_can_check_out_own_row(): void
    {
        $id = $this->seedCheckIn($this->lesseeId);
        $this->setContext($this->lesseeId, '');

        $affected = DB::connection(self::RUNTIME)->table('check_ins')
            ->where('id', $id)->update(['checked_out_at' => now()]);

        $this->assertSame(1, $affected, 'A hunter must be able to check out their own row.');
    }

    public function test_hunter_cannot_insert_for_another_user(): void
    {
        $this->setContext($this->otherUserId, '');

        $this->expectException(QueryException::class);

        DB::connection(self::RUNTIME)->table('check_ins')->insert([
            'id'            => (string) Str::uuid(),
            'lease_id'      => $this->leaseId,
            'user_id'       => $this->lesseeId, // not the current user — WITH CHECK must reject
            'checked_in_at' => now(),
        ]);
    }

    public function test_hunter_cannot_update_another_users_row(): void
    {
        $id = $this->seedCheckIn($this->lesseeId);
        $this->setContext($this->otherUserId, '');

        // USING hides the row from this user, so the UPDATE matches nothing.
        $affected = DB::connection(self::RUNTIME)->table('check_ins')
            ->where('id', $id)->update(['checked_out_at' => now()]);

        $this->assertSame(0, $affected, "A hunter must not be able to modify another user's check-in.");
    }

    public function test_staff_can_insert_on_behalf_of_user(): void
    {
        $this->setContext($this->otherUserId, 'staff');

        DB::connection(self::RUNTIME)->table('check_ins')->insert([
            'id'            => (string) Str::uuid(),
            'lease_id'      => $this->leaseId,
            'user_id'       => $this->lesseeId,
            'checked_in_at' => now(),
        ]);

        $this->assertSame(1, DB::connection('lease')->table('check_ins')
            ->where('lease_id', $this->leaseId)->where('user_id', $this->lesseeId)->count());
    }
}
