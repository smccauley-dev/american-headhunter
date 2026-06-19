<?php

namespace Tests\Feature\Lease;

use App\Exceptions\OutOfStateHuntException;
use App\Models\Identity\User;
use App\Models\Property\Property;
use App\Models\Property\PropertyListing;
use App\Services\Lease\ApplicationService;
use App\Services\Platform\EntitlementService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The authoritative single-state gate lives in ApplicationService::submit(), the
 * one chokepoint every application funnels through. It rejects an application to
 * an out-of-state listing when the applicant's membership locks them to one
 * state. Resolution of WHICH state (single_state_hunt / multi_state_hunt /
 * original_state_code) is covered by SingleStateHuntTest; here we prove the
 * submit path enforces the EntitlementService verdict and fails before any write.
 */
class OutOfStateApplicationTest extends TestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'                => $this->userId,
            'email'             => 'out-of-state@hunt.test',
            'password_hash'     => bcrypt('Password1!local'),
            'status'            => 'active',
            'account_type'      => 'hunter',
            'email_verified_at' => now(),
            'trust_score'       => 100,
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();
        DB::connection('identity')->disconnect();
        parent::tearDown();
    }

    public function test_submit_rejects_out_of_state_listing_for_restricted_hunter(): void
    {
        // A real PropertyListing carrying an out-of-state property, so the gate's
        // ?->property?->state_code read resolves without hitting DB 2.
        $property = new Property();
        $property->state_code = 'OK';
        $listing = new PropertyListing();
        $listing->setRelation('property', $property);

        $propertyService = Mockery::mock(PropertyService::class);
        $propertyService->shouldReceive('findListing')->andReturn($listing);

        $entitlements = Mockery::mock(EntitlementService::class);
        $entitlements->shouldReceive('canHuntInState')
            ->with(Mockery::type(User::class), 'OK')->andReturn(false);
        $entitlements->shouldReceive('restrictedHuntState')
            ->with(Mockery::type(User::class))->andReturn('TX');

        $this->app->instance(PropertyService::class, $propertyService);
        $this->app->instance(EntitlementService::class, $entitlements);

        $service = $this->app->make(ApplicationService::class);

        try {
            $service->submit([
                'applicant_user_id' => $this->userId,
                'listing_id'        => (string) Str::uuid(),
            ]);
            $this->fail('Expected OutOfStateHuntException to be thrown.');
        } catch (OutOfStateHuntException $e) {
            $this->assertSame('OK', $e->attemptedState, 'exception names the listing state');
            $this->assertSame('TX', $e->lockedState, 'exception names the hunter\'s locked state');
        }

        // No application row should have been written before the gate fired.
        $this->assertSame(0, DB::connection('lease')->table('lease_applications')
            ->where('applicant_user_id', $this->userId)->count());
    }
}
