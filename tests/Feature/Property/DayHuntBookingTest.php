<?php

namespace Tests\Feature\Property;

use App\Services\Lease\LeaseService;
use App\Services\Property\PropertyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Day-hunt booking calendar: lease activation reserves a listing's dates as a
 * 'booked' property_availability row (cost snapshot + lease link), cancel /
 * terminate frees them, and the DB constraints enforce the model — booked rows
 * must trace to a lease and carry a cost, and ranges for a listing can never
 * overlap (exclusive per date). Also covers the per-day + per-week quote.
 *
 * Postgres-only fixtures via owner connections (testing runs as the owner role).
 */
class DayHuntBookingTest extends TestCase
{
    private string $propertyId;
    private string $listingId;
    private string $seasonalListingId;

    /** @var list<string> */
    private array $leaseIds = [];
    /** @var list<string> */
    private array $applicationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('database.connections.property')) {
            $this->markTestSkipped('property connection not configured.');
        }

        $this->propertyId        = (string) Str::uuid();
        $this->listingId         = (string) Str::uuid();
        $this->seasonalListingId = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Day Hunt Test Ranch',
            'slug'          => 'day-hunt-test-ranch-' . Str::lower(Str::random(6)),
            'status'        => 'active',
            'state_code'    => 'TX',
            'county'        => 'Kerr',
            'total_acres'   => '500.00',
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'                      => $this->listingId,
            'property_id'             => $this->propertyId,
            'listing_type'            => 'day_hunt',
            'status'                  => 'active',
            'season_start'            => '2026-08-01',
            'season_end'              => '2026-08-31',
            'max_hunters'             => 4,
            'price_per_hunter'        => '150.00',
            'price_per_hunter_weekly' => '800.00',
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'           => $this->seasonalListingId,
            'property_id'  => $this->propertyId,
            'listing_type' => 'seasonal_lease',
            'status'       => 'active',
            'price_total'  => '5000.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_availability')
            ->where('listing_id', $this->listingId)->delete();

        if ($this->leaseIds) {
            DB::connection('lease')->table('leases')->whereIn('id', $this->leaseIds)->delete();
        }
        if ($this->applicationIds) {
            DB::connection('lease')->table('lease_applications')->whereIn('id', $this->applicationIds)->delete();
        }

        DB::connection('property')->table('property_listings')
            ->whereIn('id', [$this->listingId, $this->seasonalListingId])->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        parent::tearDown();
    }

    /** Insert an approved application + a pending lease for the given listing/dates. */
    private function createLease(string $listingId, string $start, string $end, string $total): string
    {
        $applicationId = (string) Str::uuid();
        $leaseId       = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $applicationId,
            'listing_id'        => $listingId,
            'applicant_user_id' => (string) Str::uuid(),
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id'             => $leaseId,
            'application_id' => $applicationId,
            'property_id'    => $this->propertyId,
            'listing_id'     => $listingId,
            'lessee_user_id' => (string) Str::uuid(),
            'lessor_user_id' => (string) Str::uuid(),
            'status'         => 'pending_signatures',
            'start_date'     => $start,
            'end_date'       => $end,
            'total_price'    => $total,
            'deposit_paid'   => '0.00',
        ]);

        $this->applicationIds[] = $applicationId;
        $this->leaseIds[]       = $leaseId;

        return $leaseId;
    }

    private function bookedRows(): \Illuminate\Support\Collection
    {
        return collect(DB::connection('property')->table('property_availability')
            ->where('listing_id', $this->listingId)
            ->where('reason', 'booked')
            ->get());
    }

    public function test_activating_a_day_hunt_lease_books_the_dates(): void
    {
        $leaseId = $this->createLease($this->listingId, '2026-08-05', '2026-08-07', '900.00');

        app(LeaseService::class)->activate($leaseId);

        $rows = $this->bookedRows();
        $this->assertCount(1, $rows, 'Activation should create exactly one booked range.');

        $row = $rows->first();
        $this->assertSame($leaseId, $row->lease_id);
        $this->assertSame('2026-08-05', Carbon::parse($row->date_start)->toDateString());
        $this->assertSame('2026-08-07', Carbon::parse($row->date_end)->toDateString());
        $this->assertEquals(900.00, (float) $row->cost);
    }

    public function test_cancelling_a_lease_frees_its_booked_dates(): void
    {
        $leaseId = $this->createLease($this->listingId, '2026-08-10', '2026-08-12', '450.00');

        app(LeaseService::class)->activate($leaseId);
        $this->assertCount(1, $this->bookedRows());

        app(LeaseService::class)->cancel($leaseId, 'changed plans');
        $this->assertCount(0, $this->bookedRows(), 'Cancel must release the booked dates.');
    }

    public function test_terminating_a_lease_frees_its_booked_dates(): void
    {
        $leaseId = $this->createLease($this->listingId, '2026-08-15', '2026-08-17', '450.00');

        app(LeaseService::class)->activate($leaseId);
        $this->assertCount(1, $this->bookedRows());

        app(LeaseService::class)->terminate($leaseId, 'violation');
        $this->assertCount(0, $this->bookedRows(), 'Terminate must release the booked dates.');
    }

    public function test_a_non_day_hunt_lease_does_not_touch_the_calendar(): void
    {
        $leaseId = $this->createLease($this->seasonalListingId, '2026-08-05', '2026-08-07', '5000.00');

        app(LeaseService::class)->activate($leaseId);

        $count = DB::connection('property')->table('property_availability')
            ->where('listing_id', $this->seasonalListingId)->count();
        $this->assertSame(0, $count, 'Only day-hunt listings reserve calendar dates.');
    }

    public function test_overlapping_booked_ranges_are_rejected(): void
    {
        DB::connection('property')->table('property_availability')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $this->listingId,
            'date_start' => '2026-08-20',
            'date_end'   => '2026-08-22',
            'reason'     => 'booked',
            'cost'       => '450.00',
            'lease_id'   => (string) Str::uuid(),
        ]);

        $this->expectException(QueryException::class);

        DB::connection('property')->table('property_availability')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $this->listingId,
            'date_start' => '2026-08-21', // overlaps the range above
            'date_end'   => '2026-08-23',
            'reason'     => 'booked',
            'cost'       => '450.00',
            'lease_id'   => (string) Str::uuid(),
        ]);
    }

    public function test_a_booked_row_must_carry_a_lease_and_cost(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('property')->table('property_availability')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $this->listingId,
            'date_start' => '2026-08-25',
            'date_end'   => '2026-08-26',
            'reason'     => 'booked', // no lease_id / cost — violates the CHECK
        ]);
    }

    public function test_a_blocked_row_must_not_carry_a_cost(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('property')->table('property_availability')->insert([
            'id'         => (string) Str::uuid(),
            'listing_id' => $this->listingId,
            'date_start' => '2026-08-25',
            'date_end'   => '2026-08-26',
            'reason'     => 'blocked',
            'cost'       => '100.00', // blocked rows carry no cost — violates the CHECK
        ]);
    }

    public function test_quote_applies_the_weekly_rate_to_each_full_week(): void
    {
        // 10 inclusive days = 1 full week (weekly 800) + 3 days (3 × 150 = 450) per
        // hunter = 1250; × 2 hunters = 2500.
        $quote = app(PropertyService::class)->quoteDayHunt(
            $this->listingId,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-10'),
            2,
        );

        $this->assertSame(10, $quote['days']);
        $this->assertSame(1, $quote['weeks']);
        $this->assertSame(3, $quote['extra_days']);
        $this->assertEquals(1250.00, $quote['per_hunter']);
        $this->assertEquals(2500.00, $quote['total']);
    }
}
