<?php

namespace Tests\Feature\Lease;

use App\Models\Documents\EsignatureRequest;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Vet-first booking-fee lifecycle at the ApplicationService layer.
 *
 *  - approve() opens a 24h booking-fee window without creating a lease, reservation,
 *    or signing request — the listing stays on-market so siblings can also be approved.
 *  - onBookingFeePaid() is the win path: it creates the lease from the listing's
 *    uniform terms, reserves the term (the EXCLUDE constraint is the race guard),
 *    creates the signing request, starts the 7-day completion clock, and closes the
 *    sibling applications. A reservation conflict means another payer won first — the
 *    half-built lease is HARD-deleted and the application closed ('lost') so the
 *    caller refunds the fee.
 *  - closeForUnpaidBookingFee() closes an approved application whose window lapsed.
 *
 * Postgres fixtures via owner connections (testing runs as the owner role, RLS bypassed).
 */
class BookingFeeLifecycleTest extends TestCase
{
    private string $propertyId;
    private string $listingId;
    private string $applicationId;
    private string $reviewerId;
    private string $applicantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('database.connections.property')) {
            $this->markTestSkipped('property connection not configured.');
        }

        $this->propertyId    = (string) Str::uuid();
        $this->listingId     = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->reviewerId    = (string) Str::uuid();
        $this->applicantId   = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Booking Fee Test Ranch',
            'slug'          => 'booking-fee-test-' . Str::lower(Str::random(6)),
            'status'        => 'active',
            'state_code'    => 'WV',
            'county'        => 'Boone',
            'total_acres'   => '320.00',
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'           => $this->listingId,
            'property_id'  => $this->propertyId,
            'listing_type' => 'seasonal_lease',
            'status'       => 'active',
            'price_total'  => '5000.00',
            'season_start' => '2026-10-15',
            'season_end'   => '2027-01-08',
        ]);

        $this->insertApplication('pending');
    }

    private function insertApplication(string $status): void
    {
        DB::connection('lease')->table('lease_applications')->insert([
            'id'                        => $this->applicationId,
            'listing_id'                => $this->listingId,
            'applicant_user_id'         => $this->applicantId,
            'application_type'          => 'individual',
            'status'                    => $status,
            'property_id_snapshot'      => $this->propertyId,
            'listing_season_start_snap' => '2026-10-15',
            'listing_season_end_snap'   => '2027-01-08',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_availability')->where('listing_id', $this->listingId)->delete();
        DB::connection('lease')->table('lease_hunters')->whereIn('lease_id',
            DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->pluck('id'))->delete();
        DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->delete();
        DB::connection('lease')->table('lease_application_review_history')->where('application_id', $this->applicationId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('property')->table('property_listings')->where('id', $this->listingId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        parent::tearDown();
    }

    /** A mocked e-sign provider that records exactly one request without hitting Dropbox. */
    private function mockEsign(): void
    {
        $esig = Mockery::mock(EsignatureService::class);
        $esig->shouldReceive('createRequest')->once()->andReturn(new EsignatureRequest());
        $this->instance(EsignatureService::class, $esig);
    }

    // ── approve() ───────────────────────────────────────────────────────────────

    public function test_approve_opens_a_24h_window_without_creating_a_lease(): void
    {
        $before = now();

        app(ApplicationService::class)->approve($this->applicationId, $this->reviewerId);

        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('approved', $app->status);
        $this->assertNotNull($app->booking_fee_deadline);

        $deadline = Carbon::parse($app->booking_fee_deadline);
        $this->assertEqualsWithDelta(24, $before->diffInHours($deadline, false), 1);

        // No lease, reservation, or signing request — those wait for the booking fee.
        $this->assertSame(0, DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->count());
        $this->assertSame(0, DB::connection('property')->table('property_availability')->where('listing_id', $this->listingId)->count());
    }

    public function test_approve_rejects_an_application_that_is_not_pending(): void
    {
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->update(['status' => 'closed']);

        $this->expectException(\RuntimeException::class);
        app(ApplicationService::class)->approve($this->applicationId, $this->reviewerId);
    }

    // ── onBookingFeePaid(): win ───────────────────────────────────────────────────

    public function test_paying_the_booking_fee_creates_the_lease_and_reserves_the_term(): void
    {
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)
            ->update(['status' => 'approved', 'booking_fee_deadline' => now()->addDay()]);

        $this->mockEsign();

        $result = app(ApplicationService::class)->onBookingFeePaid($this->applicationId, $this->applicantId);

        $this->assertSame('won', $result['outcome']);
        $this->assertInstanceOf(Lease::class, $result['lease']);

        $lease = $result['lease'];
        $this->assertSame('pending_signatures', $lease->status);
        $this->assertSame('5000.00', (string) $lease->total_price);   // uniform terms from the listing
        $this->assertNotNull($lease->completion_deadline);            // 7-day completion clock started
        $this->assertEqualsWithDelta(7, now()->diffInDays($lease->completion_deadline, false), 1);

        // The exclusive term was reserved (the EXCLUDE constraint is the race guard).
        $this->assertGreaterThan(
            0,
            DB::connection('property')->table('property_availability')->where('listing_id', $this->listingId)->count(),
        );
    }

    public function test_paying_is_idempotent_when_a_lease_already_exists(): void
    {
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)
            ->update(['status' => 'approved', 'booking_fee_deadline' => now()->addDay()]);

        $this->mockEsign();
        $first = app(ApplicationService::class)->onBookingFeePaid($this->applicationId, $this->applicantId);

        // A redelivered webhook must not create a second lease or re-reserve.
        $second = app(ApplicationService::class)->onBookingFeePaid($this->applicationId, $this->applicantId);

        $this->assertSame('won', $second['outcome']);
        $this->assertSame($first['lease']->id, $second['lease']->id);
        $this->assertSame(1, DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->whereNull('deleted_at')->count());
    }

    // ── onBookingFeePaid(): lost ──────────────────────────────────────────────────

    public function test_losing_the_reservation_race_hard_deletes_the_lease_and_closes_the_application(): void
    {
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)
            ->update(['status' => 'approved', 'booking_fee_deadline' => now()->addDay()]);

        // A concurrent payer already reserved the term: reserveExclusiveLease throws.
        $listing = new PropertyListing(['season_start' => '2026-10-15', 'season_end' => '2027-01-08', 'price_total' => 5000]);
        $properties = Mockery::mock(PropertyService::class);
        $properties->shouldReceive('findListing')->andReturn($listing);
        $properties->shouldReceive('reserveExclusiveLease')->once()->andThrow(new \RuntimeException('term already reserved'));
        $properties->shouldReceive('releaseBooking')->andReturnNull();
        $this->instance(PropertyService::class, $properties);

        $result = app(ApplicationService::class)->onBookingFeePaid($this->applicationId, $this->applicantId);

        $this->assertSame('lost', $result['outcome']);
        $this->assertNull($result['lease']);

        // The application is closed (the caller refunds), and no lease row lingers —
        // a soft-deleted row would trip uq_leases_application_id on any retry.
        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('closed', $app->status);
        $this->assertNull(DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->first());
    }

    public function test_paying_after_the_window_closed_is_a_loss(): void
    {
        // The applicant paid but their window had already lapsed (status no longer approved).
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->update(['status' => 'closed']);

        $result = app(ApplicationService::class)->onBookingFeePaid($this->applicationId, $this->applicantId);

        $this->assertSame('lost', $result['outcome']);
        $this->assertNull($result['lease']);
        $this->assertSame(0, DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->count());
    }

    // ── closeForUnpaidBookingFee() ────────────────────────────────────────────────

    public function test_close_for_unpaid_booking_fee_closes_an_approved_application(): void
    {
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)
            ->update(['status' => 'approved', 'booking_fee_deadline' => now()->subHour()]);

        app(ApplicationService::class)->closeForUnpaidBookingFee($this->applicationId);

        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('closed', $app->status);
        $this->assertSame('Booking Fee was not paid', $app->closed_reason);
    }

    public function test_close_for_unpaid_booking_fee_is_a_noop_when_not_approved(): void
    {
        // A winner's application stays 'approved' until it wins; a non-approved row
        // (e.g. already closed) must be left untouched.
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->update(['status' => 'pending']);

        app(ApplicationService::class)->closeForUnpaidBookingFee($this->applicationId);

        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('pending', $app->status);
    }
}
