<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-043 regression — Row-Level Security is actually enforced for the
 * user-facing runtime role.
 *
 * The original defect: the application connected to every database as the
 * schema OWNER (ah_app). A table owner is exempt from RLS unless the table is
 * marked FORCE ROW LEVEL SECURITY, so every USING/WITH CHECK policy was a
 * no-op and any authenticated request could read any tenant's rows.
 *
 * The fix: user-facing HTTP requests now connect as the non-owner `ah_runtime`
 * role (RLS applies); trusted, pre-context subsystems connect as `ah_system`
 * (BYPASSRLS). This test connects EXPLICITLY as ah_runtime — not the owner the
 * test kernel otherwise uses — and proves the four enforcement outcomes against
 * the real `leases` table (DB 3):
 *
 *   1. no context        -> 0 rows  (default-deny)
 *   2. own context       -> own row visible
 *   3. other-user context-> own row NOT visible
 *   4. staff role        -> all rows visible (admin override)
 *
 * If anyone reverts the app to connecting as the owner, drops the policy, or
 * disables RLS on the table, the corresponding assertion fails.
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable
 * (e.g. a sqlite-only CI environment without the live cluster).
 */
class RlsEnforcementTest extends TestCase
{
    private const RUNTIME = 'lease_rls_test';

    private string $leaseId;
    private string $applicationId;
    private string $lesseeId;
    private string $otherUserId;

    protected function setUp(): void
    {
        parent::setUp();

        // A dedicated connection that authenticates as the non-owner runtime
        // role, regardless of the owner credentials the test kernel uses for
        // the app's own `lease` connection.
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

        // Fixtures are written via the OWNER connection (bypasses RLS on write).
        // leases.application_id has a same-DB FK to lease_applications, so the
        // parent row is created first.
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
            DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        }
        if (isset($this->applicationId)) {
            DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        }
        DB::purge(self::RUNTIME);
        parent::tearDown();
    }

    /** Count how many times the fixture lease is visible under a given RLS context. */
    private function fixtureVisibleAs(string $userId, string $role): int
    {
        $conn = DB::connection(self::RUNTIME);
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));

        return DB::connection(self::RUNTIME)->table('leases')->where('id', $this->leaseId)->count();
    }

    public function test_runtime_role_is_not_the_table_owner(): void
    {
        // The whole fix rests on the runtime role being a NON-owner — owners
        // bypass RLS without FORCE. Guard against a future regrant.
        $owner = DB::connection('lease')->selectOne(
            "SELECT tableowner FROM pg_tables WHERE tablename = 'leases'"
        );
        $runtime = env('DB_LEASE_USERNAME', 'ah_runtime');

        $this->assertNotSame($owner->tableowner, $runtime,
            'Runtime role must not own the leases table, or RLS is bypassed.');
    }

    public function test_rls_is_enabled_on_leases(): void
    {
        $row = DB::connection('lease')->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'leases'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on leases.');
    }

    public function test_no_context_denies_all_rows(): void
    {
        $this->assertSame(0, $this->fixtureVisibleAs('', ''),
            'With no RLS context the runtime role must see zero rows (default-deny).');
    }

    public function test_own_context_sees_own_row(): void
    {
        $this->assertSame(1, $this->fixtureVisibleAs($this->lesseeId, ''),
            'The lessee must see their own lease.');
    }

    public function test_other_user_context_cannot_see_row(): void
    {
        $this->assertSame(0, $this->fixtureVisibleAs($this->otherUserId, ''),
            'A different user must not see another tenant\'s lease.');
    }

    public function test_staff_role_sees_row(): void
    {
        $this->assertSame(1, $this->fixtureVisibleAs($this->otherUserId, 'staff'),
            'Staff role override must see all leases regardless of ownership.');
    }
}
