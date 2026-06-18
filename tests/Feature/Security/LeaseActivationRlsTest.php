<?php

namespace Tests\Feature\Security;

use App\Database\ConnectionRole;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-046 regression — lease activation persists when the final e-signature is
 * recorded under the non-owner ah_runtime role (lessee signs last via the
 * member portal).
 *
 * The defect: `leases` has only a FOR SELECT policy (leases_parties_and_staff).
 * With RLS enabled and no permissive write policy, an UPDATE under ah_runtime
 * matches zero rows *without raising*. When the lessee signed last from the
 * member portal (ah_runtime), EsignatureService::activateIfComplete →
 * LeaseService::activate ran `UPDATE leases SET status='active'` → silent no-op,
 * yet still reported success. The lease stayed `pending_signatures`. SEC-045's
 * sweep wrongly classified `leases` as "ah_system-only" and missed this path.
 *
 * The fix runs the completion writes under ah_system (BYPASSRLS) via
 * ConnectionRole::asSystem — leases stay write-locked to trusted roles (no
 * runtime write policy that would let a party forge a lease) — and
 * LeaseService::activate now re-reads and throws if the write didn't persist.
 *
 * Postgres-only. Skips cleanly when an ah_runtime connection is unavailable.
 */
class LeaseActivationRlsTest extends TestCase
{
    /** Owner connection used for fixtures/assertions (bypasses RLS). */
    private const OWNER = 'lease_activation_owner';

    private array $baseLeaseConfig;
    private string $leaseId;
    private string $applicationId;
    private string $lesseeId;
    private string $lessorId;

    protected function setUp(): void
    {
        parent::setUp();

        $base = config('database.connections.lease');
        if (! $base) {
            $this->markTestSkipped('lease connection not configured.');
        }
        $this->baseLeaseConfig = $base;

        // An owner-creds connection for fixtures + assertions, independent of the
        // role we swap the default `lease` connection to below.
        config(['database.connections.' . self::OWNER => $base]);

        $this->lesseeId      = (string) Str::uuid();
        $this->lessorId      = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        DB::connection(self::OWNER)->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection(self::OWNER)->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => $this->lessorId,
            'status'         => 'pending_signatures',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);

        // Point the DEFAULT `lease` connection (the one models use) at ah_runtime
        // so the service exercises the real RLS-enforced path.
        config([
            'database.connections.lease.username' => env('DB_LEASE_USERNAME', 'ah_runtime'),
            'database.connections.lease.password' => env('DB_LEASE_PASSWORD', 'secret'),
        ]);
        DB::purge('lease');

        try {
            DB::connection('lease')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('ah_runtime Postgres connection unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Restore the owner default before cleanup.
        config([
            'database.connections.lease.username' => $this->baseLeaseConfig['username'] ?? null,
            'database.connections.lease.password' => $this->baseLeaseConfig['password'] ?? null,
        ]);
        DB::purge('lease');

        if (isset($this->leaseId)) {
            DB::connection(self::OWNER)->table('leases')->where('id', $this->leaseId)->delete();
        }
        if (isset($this->applicationId)) {
            DB::connection(self::OWNER)->table('lease_applications')->where('id', $this->applicationId)->delete();
        }
        DB::purge(self::OWNER);

        parent::tearDown();
    }

    /** Apply an RLS context to the runtime `lease` connection's session. */
    private function setContext(string $userId, string $role): void
    {
        $conn = DB::connection('lease');
        $conn->unprepared('SET app.current_user_id = ' . $conn->getPdo()->quote($userId));
        $conn->unprepared('SET app.user_role = ' . $conn->getPdo()->quote($role));
    }

    public function test_rls_enabled_on_leases(): void
    {
        $row = DB::connection(self::OWNER)->selectOne(
            "SELECT relrowsecurity FROM pg_class WHERE relname = 'leases'"
        );
        $this->assertTrue((bool) $row->relrowsecurity, 'RLS must be enabled on leases.');
    }

    public function test_runtime_update_to_leases_is_a_silent_no_op(): void
    {
        // The precondition this fix exists for: a SELECT-only policy means an
        // ah_runtime UPDATE affects zero rows without raising.
        $this->setContext($this->lesseeId, '');

        $affected = DB::connection('lease')->table('leases')
            ->where('id', $this->leaseId)
            ->update(['status' => 'active']);

        $this->assertSame(0, $affected, 'leases has no UPDATE policy for ah_runtime — the update must affect zero rows.');
        $this->assertSame('pending_signatures', DB::connection(self::OWNER)->table('leases')
            ->where('id', $this->leaseId)->value('status'));
    }

    public function test_activate_throws_when_run_directly_under_runtime(): void
    {
        // Without the asSystem wrapper the write silently no-ops; the new guard in
        // LeaseService::activate must catch that and fail loudly.
        $this->setContext($this->lesseeId, '');

        $this->expectException(\RuntimeException::class);
        app(LeaseService::class)->activate($this->leaseId);

        $this->assertSame('pending_signatures', DB::connection(self::OWNER)->table('leases')
            ->where('id', $this->leaseId)->value('status'));
    }

    public function test_activation_persists_when_wrapped_in_assystem(): void
    {
        // The fix: running the activation under ah_system persists the write even
        // though the default connection is ah_runtime.
        ConnectionRole::asSystem(fn () => app(LeaseService::class)->activate($this->leaseId));

        $this->assertSame('active', DB::connection(self::OWNER)->table('leases')
            ->where('id', $this->leaseId)->value('status'));
    }
}
