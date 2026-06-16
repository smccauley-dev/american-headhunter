<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::userCanManageProperty / getManagedPropertySummaries — the
 * ownership scoping that gates the member-portal landowner property management.
 * The `properties` table has NO RLS policy, so this service check is the only
 * thing standing between a user and someone else's property. These tests pin
 * that boundary: owner and active managers in, revoked/unrelated users out.
 */
class ManagedPropertiesTest extends TestCase
{
    private string $ownerId;
    private string $managerId;
    private string $revokedId;
    private string $strangerId;
    private string $ownedPropertyId;
    private string $managedPropertyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId           = (string) Str::uuid();
        $this->managerId         = (string) Str::uuid();
        $this->revokedId         = (string) Str::uuid();
        $this->strangerId        = (string) Str::uuid();
        $this->ownedPropertyId   = (string) Str::uuid();
        $this->managedPropertyId = (string) Str::uuid();
        $this->listingId         = (string) Str::uuid();

        // Property the owner owns outright.
        DB::connection('property')->table('properties')->insert([
            'id'             => $this->ownedPropertyId,
            'owner_user_id'  => $this->ownerId,
            'title'          => 'Owned Tract',
            'slug'           => "owned-{$this->ownedPropertyId}",
            'status'         => 'active',
            'state_code'     => 'TX',
            'county'         => 'Llano',
            'total_acres'    => '640.00',
            'huntable_acres' => '500.00',
        ]);

        // Property owned by someone else but managed via a grant.
        DB::connection('property')->table('properties')->insert([
            'id'            => $this->managedPropertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Managed Tract',
            'slug'          => "managed-{$this->managedPropertyId}",
            'status'        => 'draft',
            'state_code'    => 'OK',
            'county'        => 'Osage',
            'total_acres'   => '320.00',
        ]);

        // One active listing on the owned property (asserts the count logic).
        DB::connection('property')->table('property_listings')->insert([
            'id'          => $this->listingId,
            'property_id' => $this->ownedPropertyId,
            'listing_type' => 'annual_lease',
            'status'      => 'active',
            'visibility'  => 'public',
            'max_hunters' => 4,
        ]);

        // Active manager grant for the managed property.
        DB::connection('property')->table('property_managers')->insert([
            'id'                 => (string) Str::uuid(),
            'property_id'        => $this->managedPropertyId,
            'user_id'            => $this->managerId,
            'role'               => 'manager',
            'granted_by_user_id' => $this->ownerId,
            'granted_at'         => now(),
        ]);

        // Revoked grant on the owned property — must NOT confer access.
        DB::connection('property')->table('property_managers')->insert([
            'id'                 => (string) Str::uuid(),
            'property_id'        => $this->ownedPropertyId,
            'user_id'            => $this->revokedId,
            'role'               => 'manager',
            'granted_by_user_id' => $this->ownerId,
            'granted_at'         => now()->subDays(10),
            'revoked_at'         => now()->subDay(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_listings')->where('property_id', $this->ownedPropertyId)->delete();
        DB::connection('property')->table('property_managers')->whereIn('property_id', [$this->ownedPropertyId, $this->managedPropertyId])->delete();
        DB::connection('property')->table('properties')->whereIn('id', [$this->ownedPropertyId, $this->managedPropertyId])->delete();

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    public function test_owner_can_manage_their_property(): void
    {
        $this->assertTrue($this->service()->userCanManageProperty($this->ownerId, $this->ownedPropertyId));
    }

    public function test_active_manager_can_manage_the_granted_property(): void
    {
        $this->assertTrue($this->service()->userCanManageProperty($this->managerId, $this->managedPropertyId));
    }

    public function test_revoked_manager_cannot_manage(): void
    {
        $this->assertFalse($this->service()->userCanManageProperty($this->revokedId, $this->ownedPropertyId));
    }

    public function test_unrelated_user_cannot_manage(): void
    {
        $this->assertFalse($this->service()->userCanManageProperty($this->strangerId, $this->ownedPropertyId));
        $this->assertFalse($this->service()->userCanManageProperty($this->strangerId, $this->managedPropertyId));
    }

    public function test_summaries_return_owned_and_managed_with_role_and_counts(): void
    {
        $owner = collect($this->service()->getManagedPropertySummaries($this->ownerId));
        $owned = $owner->firstWhere('id', $this->ownedPropertyId);

        $this->assertNotNull($owned);
        $this->assertSame('owner', $owned['role']);
        $this->assertSame(1, $owned['listings_count']);
        $this->assertSame(1, $owned['active_listings_count']);

        $manager = collect($this->service()->getManagedPropertySummaries($this->managerId));
        $managed = $manager->firstWhere('id', $this->managedPropertyId);

        $this->assertNotNull($managed);
        $this->assertSame('manager', $managed['role']);
    }

    public function test_summaries_are_empty_for_unrelated_user(): void
    {
        $this->assertSame([], $this->service()->getManagedPropertySummaries($this->strangerId));
    }
}
