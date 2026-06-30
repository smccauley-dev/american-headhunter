<?php

namespace Tests\Feature\Lease;

use App\Models\Lease\CheckIn;
use App\Services\Lease\CheckInService;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * When a lease leaves the active state, a hunter who forgot to check out must no
 * longer be shown "in the field". Two guarantees:
 *
 *   1. Each lease transition (terminate/cancel/expire/...) closes every open
 *      check-in on the lease (closeOpenForLease).
 *   2. The dashboard's getOpenForUser ignores check-ins on a non-active lease,
 *      so any historical straggler is hidden without a data backfill.
 *
 * Real rows on the lease connection (tests run as owner → RLS bypassed).
 */
class CheckInAutoCloseTest extends TestCase
{
    private string $leaseId;
    private string $applicationId;
    private string $lesseeId;
    private string $lessorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->lesseeId      = (string) Str::uuid();
        $this->lessorId      = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id'    => (string) Str::uuid(),
            'listing_id'     => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => $this->lessorId,
            'status'         => 'active',
            'start_date'     => '2026-10-01',
            'end_date'       => '2026-11-30',
            'total_price'    => '2500.00',
            'deposit_paid'   => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('check_ins')->where('lease_id', $this->leaseId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        parent::tearDown();
    }

    private function openCheckIn(): CheckIn
    {
        return CheckIn::create([
            'lease_id'      => $this->leaseId,
            'user_id'       => $this->lesseeId,
            'checked_in_at' => now()->subHours(3),
        ]);
    }

    public function test_terminating_a_lease_closes_an_open_check_in(): void
    {
        $checkIn = $this->openCheckIn();

        app(LeaseService::class)->terminate($this->leaseId, 'Lease ended early', $this->lessorId);

        $checkIn->refresh();
        $this->assertNotNull($checkIn->checked_out_at, 'Termination must close the open check-in.');
    }

    public function test_close_open_for_lease_closes_every_open_row_and_leaves_closed_ones(): void
    {
        $open      = $this->openCheckIn();
        $already   = CheckIn::create([
            'lease_id'       => $this->leaseId,
            'user_id'        => (string) Str::uuid(),
            'checked_in_at'  => now()->subDays(2),
            'checked_out_at' => now()->subDays(2)->addHours(2),
        ]);
        $closedAt = $already->checked_out_at;

        $count = app(CheckInService::class)->closeOpenForLease($this->leaseId);

        $this->assertSame(1, $count);
        $open->refresh();
        $this->assertNotNull($open->checked_out_at);
        $already->refresh();
        // An already-closed row keeps its original check-out time.
        $this->assertEquals($closedAt->toIso8601String(), $already->checked_out_at->toIso8601String());
    }

    public function test_dashboard_ignores_an_open_check_in_on_a_non_active_lease(): void
    {
        // Simulate a historical straggler: an open check-in whose lease is no
        // longer active (e.g. the transition predates closeOpenForLease).
        $this->openCheckIn();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->update(['status' => 'terminated']);

        $open = app(CheckInService::class)->getOpenForUser($this->lesseeId);

        $this->assertNull($open, 'A check-in on a terminated lease must not count as "in the field".');
    }

    public function test_dashboard_still_surfaces_an_open_check_in_on_an_active_lease(): void
    {
        $this->openCheckIn();

        $open = app(CheckInService::class)->getOpenForUser($this->lesseeId);

        $this->assertNotNull($open);
        $this->assertSame($this->leaseId, $open->lease_id);
    }
}
