<?php

namespace Tests\Feature\Lease;

use App\Models\Billing\BookingDeposit;
use App\Services\Billing\BookingDepositService;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * booking:enforce-deadlines selection logic:
 *
 *  - An approved application whose 24h booking window lapsed UNPAID is closed.
 *  - An approved application that lapsed but HAS a booking-fee row is skipped (the
 *    applicant paid — they won or were refunded; not an unpaid-window closure).
 *  - An approved application still inside its window is left alone.
 *  - A lease whose 7-day completion window lapsed is forfeited + cancelled.
 *  - A lease outside the window (or active) is left alone.
 *
 * The forfeit/cancel collaborators are mocked (their behavior is covered by
 * BookingDepositServiceTest / the lease tests) so this exercises the command's row
 * selection without Stripe or reservation fixtures.
 */
class EnforceBookingDeadlinesTest extends TestCase
{
    /** @var array<int,string> */ private array $applicationIds = [];
    /** @var array<int,string> */ private array $leaseIds = [];

    protected function tearDown(): void
    {
        $lease = DB::connection('lease');
        // Leases first — leases.application_id FK-references lease_applications.
        if ($this->leaseIds) {
            $lease->table('leases')->whereIn('id', $this->leaseIds)->delete();
        }
        if ($this->applicationIds) {
            $lease->table('lease_applications')->whereIn('id', $this->applicationIds)->delete();
        }
        parent::tearDown();
    }

    private function seedApplication(string $status, ?\DateTimeInterface $deadline): string
    {
        $id = (string) Str::uuid();
        DB::connection('lease')->table('lease_applications')->insert([
            'id'                   => $id,
            'listing_id'           => (string) Str::uuid(),
            'applicant_user_id'    => (string) Str::uuid(),
            'application_type'     => 'individual',
            'status'               => $status,
            'booking_fee_deadline' => $deadline,
        ]);
        $this->applicationIds[] = $id;

        return $id;
    }

    private function seedLease(string $status, ?\DateTimeInterface $completionDeadline): string
    {
        // leases.application_id has a same-DB FK to lease_applications.
        $applicationId = $this->seedApplication('approved', null);

        $id = (string) Str::uuid();
        DB::connection('lease')->table('leases')->insert([
            'id'                  => $id,
            'application_id'      => $applicationId,
            'property_id'         => (string) Str::uuid(),
            'listing_id'          => (string) Str::uuid(),
            'lessee_user_id'      => (string) Str::uuid(),
            'lessor_user_id'      => (string) Str::uuid(),
            'status'              => $status,
            'start_date'          => '2026-10-15',
            'end_date'            => '2027-01-08',
            'total_price'         => '5000.00',
            'completion_deadline' => $completionDeadline,
        ]);
        $this->leaseIds[] = $id;

        return $id;
    }

    public function test_it_closes_unpaid_windows_skips_paid_and_forfeits_lapsed_leases(): void
    {
        $unpaid   = $this->seedApplication('approved', now()->subHour());   // lapsed, no deposit → close
        $paid     = $this->seedApplication('approved', now()->subHour());   // lapsed, but paid → skip
        $inWindow = $this->seedApplication('approved', now()->addHour());   // still open → leave

        $lapsedLease = $this->seedLease('pending_signatures', now()->subDay()); // lapsed → forfeit + cancel
        $this->seedLease('pending_signatures', now()->addDay());                // still open → leave
        $this->seedLease('active', now()->subDay());                            // active → leave

        // The paid application has a booking-fee row; the unpaid one does not. The
        // command scans globally, so tolerate any other id (resolve to "unpaid").
        $deposits = Mockery::mock(BookingDepositService::class);
        $deposits->shouldReceive('forApplication')->andReturnUsing(
            fn (string $id) => $id === $paid ? new BookingDeposit() : null,
        );
        $deposits->shouldReceive('forfeitForLease')->andReturnNull();
        $this->app->instance(BookingDepositService::class, $deposits);

        $leases = Mockery::mock(LeaseService::class);
        $leases->shouldReceive('cancel')->andReturnNull();
        $this->app->instance(LeaseService::class, $leases);

        $this->artisan('booking:enforce-deadlines')->assertSuccessful();

        // The lapsed lease was forfeited and cancelled; the open/active ones were not.
        $deposits->shouldHaveReceived('forfeitForLease')->with($lapsedLease);
        $leases->shouldHaveReceived('cancel')->with($lapsedLease, Mockery::type('string'));

        $row = fn (string $id) => DB::connection('lease')->table('lease_applications')->where('id', $id)->first();

        $this->assertSame('closed', $row($unpaid)->status);
        $this->assertSame('Booking Fee was not paid', $row($unpaid)->closed_reason);
        $this->assertSame('approved', $row($paid)->status, 'a paid application must not be closed as unpaid');
        $this->assertSame('approved', $row($inWindow)->status, 'an open window must be left alone');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $unpaid = $this->seedApplication('approved', now()->subHour());

        $deposits = Mockery::mock(BookingDepositService::class);
        $deposits->shouldReceive('forApplication')->andReturnNull();
        $deposits->shouldNotReceive('forfeitForLease');
        $this->app->instance(BookingDepositService::class, $deposits);

        $leases = Mockery::mock(LeaseService::class);
        $leases->shouldNotReceive('cancel');
        $this->app->instance(LeaseService::class, $leases);

        $this->artisan('booking:enforce-deadlines', ['--dry-run' => true])
            ->expectsOutputToContain('Would close')
            ->assertSuccessful();

        $app = DB::connection('lease')->table('lease_applications')->where('id', $unpaid)->first();
        $this->assertSame('approved', $app->status, 'dry-run must not write');
    }
}
