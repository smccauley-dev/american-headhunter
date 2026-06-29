<?php

namespace Tests\Feature\Lease;

use App\Models\Documents\EsignatureRequest;
use App\Models\Lease\Lease;
use App\Services\Lease\ApplicationService;
use App\Services\Lease\EsignatureService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Approving an exclusive (seasonal) lease application creates the lease and
 * reserves the listing term. Regression coverage for two bugs found together:
 *
 *  1. ApplicationService imported Carbon\Carbon while
 *     PropertyService::reserveExclusiveLease requires Illuminate\Support\Carbon,
 *     so the reservation call threw a TypeError on every exclusive approval.
 *  2. The failure compensation reverted the application via a stale model
 *     instance, so Eloquent dirty-checking no-op'd the status write — the lease
 *     was deleted but the application stayed 'approved' ("left unchanged" lied).
 *
 * Postgres fixtures via owner connections (testing runs as the owner role).
 */
class ApproveAndCreateLeaseTest extends TestCase
{
    private string $propertyId;
    private string $listingId;
    private string $applicationId;
    private string $reviewerId;

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

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Exclusive Approval Test Ranch',
            'slug'          => 'exclusive-approval-test-' . Str::lower(Str::random(6)),
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
        ]);

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                   => $this->applicationId,
            'listing_id'           => $this->listingId,
            'applicant_user_id'    => (string) Str::uuid(),
            'application_type'     => 'individual',
            'status'               => 'pending',
            'property_id_snapshot' => $this->propertyId,
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

    private array $terms = ['start_date' => '2026-10-15', 'end_date' => '2027-01-08', 'total_price' => 5000];

    public function test_approving_an_exclusive_listing_creates_the_lease_and_reserves_the_term(): void
    {
        // Don't hit the e-sign provider — the reservation step (real) is the unit
        // under test; the signing request only needs to not throw.
        $esig = Mockery::mock(EsignatureService::class);
        $esig->shouldReceive('createRequest')->once()->andReturn(new EsignatureRequest());
        $this->instance(EsignatureService::class, $esig);

        $result = app(ApplicationService::class)->approveAndCreateLease(
            $this->applicationId,
            $this->reviewerId,
            $this->terms,
            null,
            false,
        );

        $this->assertInstanceOf(Lease::class, $result['lease']);

        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('approved', $app->status);

        // The exclusive term was reserved — proves the Carbon type now lines up.
        $reserved = DB::connection('property')->table('property_availability')
            ->where('listing_id', $this->listingId)->count();
        $this->assertGreaterThan(0, $reserved);
    }

    public function test_a_reservation_failure_reverts_the_application_to_pending(): void
    {
        // Force the reservation to fail after the lease is created, exercising the
        // compensation path. PropertyService is only used here for the reservation
        // and the rollback's releaseBooking, so a full mock is safe.
        $properties = Mockery::mock(PropertyService::class);
        $properties->shouldReceive('reserveExclusiveLease')->once()->andThrow(new \RuntimeException('reservation boom'));
        $properties->shouldReceive('releaseBooking')->andReturnNull();
        $this->instance(PropertyService::class, $properties);

        try {
            app(ApplicationService::class)->approveAndCreateLease(
                $this->applicationId,
                $this->reviewerId,
                $this->terms,
                null,
                false,
            );
            $this->fail('Expected the reservation failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('reservation boom', $e->getMessage());
        }

        // Compensation must actually revert the application — not silently no-op.
        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('pending', $app->status);
        $this->assertNull($app->reviewed_at);
        $this->assertNull($app->reviewed_by_user_id);

        // The half-created lease was rolled back and hard-deleted, so the row is
        // gone entirely — leaving a soft-deleted row would trip the plain
        // uq_leases_application_id unique index on any re-approval.
        $lease = DB::connection('lease')->table('leases')->where('listing_id', $this->listingId)->first();
        $this->assertNull($lease, 'The compensated lease row should be removed entirely.');
    }

    public function test_a_failed_approval_can_be_retried_successfully(): void
    {
        // The reservation fails the first time and succeeds the second. The first
        // failure's compensation must HARD-delete the lease — a soft delete would
        // leave a row that trips the plain uq_leases_application_id unique index,
        // breaking the retry with "duplicate key value" (the bug the user hit).
        // One mock for both calls: ApplicationService is a singleton, so the
        // PropertyService it captured in its constructor is reused across calls.
        $reserveCalls = 0;
        $properties = Mockery::mock(PropertyService::class);
        $properties->shouldReceive('reserveExclusiveLease')->twice()->andReturnUsing(
            function () use (&$reserveCalls) {
                if (++$reserveCalls === 1) {
                    throw new \RuntimeException('reservation boom');
                }
                return null;
            }
        );
        $properties->shouldReceive('releaseBooking')->andReturnNull();
        $this->instance(PropertyService::class, $properties);

        // Only the successful (second) approval reaches the e-sign step.
        $esig = Mockery::mock(EsignatureService::class);
        $esig->shouldReceive('createRequest')->once()->andReturn(new EsignatureRequest());
        $this->instance(EsignatureService::class, $esig);

        try {
            app(ApplicationService::class)->approveAndCreateLease(
                $this->applicationId, $this->reviewerId, $this->terms, null, false,
            );
            $this->fail('Expected the first reservation failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('reservation boom', $e->getMessage());
        }

        // Retry — must succeed, not hit a leftover-lease unique violation.
        $result = app(ApplicationService::class)->approveAndCreateLease(
            $this->applicationId, $this->reviewerId, $this->terms, null, false,
        );

        $this->assertInstanceOf(Lease::class, $result['lease']);
        $app = DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->first();
        $this->assertSame('approved', $app->status);
    }
}
