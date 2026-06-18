<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::grantManager / revokeManager — the member-portal "Manage Team"
 * tab (Slice 5). Managers are resolved by email against the identity DB (no
 * cross-DB FK), recorded in DB 2 property_managers, and revoked by id scoped to
 * their property so a foreign grant id is a no-op rather than a cross-property
 * revoke. These tests pin grant-by-email, the duplicate/no-such-user guards, and
 * the property-scoped revoke.
 */
class PropertyManagersTest extends TestCase
{
    private string $ownerId;
    private string $targetId;
    private string $propertyId;
    private string $otherPropertyId;
    private string $targetEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId         = (string) Str::uuid();
        $this->targetId        = (string) Str::uuid();
        $this->propertyId      = (string) Str::uuid();
        $this->otherPropertyId = (string) Str::uuid();
        $this->targetEmail     = "manager-{$this->targetId}@test.invalid";

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->ownerId,
            'email'         => "owner-{$this->ownerId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'landowner',
        ]);
        DB::connection('identity')->table('users')->insert([
            'id'            => $this->targetId,
            'email'         => $this->targetEmail,
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->targetId,
            'first_name' => 'Pat',
            'last_name'  => 'Manager',
        ]);

        foreach ([$this->propertyId, $this->otherPropertyId] as $i => $id) {
            DB::connection('property')->table('properties')->insert([
                'id'            => $id,
                'owner_user_id' => $this->ownerId,
                'title'         => "Tract {$i}",
                'slug'          => "tract-{$id}",
                'status'        => 'active',
                'state_code'    => 'TX',
                'county'        => 'Llano',
                'total_acres'   => '640.00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_managers')->whereIn('property_id', [$this->propertyId, $this->otherPropertyId])->delete();
        DB::connection('property')->table('properties')->whereIn('id', [$this->propertyId, $this->otherPropertyId])->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->targetId)->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->ownerId, $this->targetId])->delete();

        foreach (['property', 'property_read', 'identity'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    /**
     * The team minus the synthesized owner row — getManagersForProperty always
     * lists the owner of record first, so the grant/revoke assertions below
     * isolate the actual manager grants.
     *
     * @return array<int, array<string, mixed>>
     */
    private function managerRows(string $propertyId): array
    {
        return array_values(array_filter(
            $this->service()->getManagersForProperty($propertyId),
            fn (array $row) => $row['role'] !== 'owner',
        ));
    }

    public function test_owner_of_record_is_listed_first(): void
    {
        $team = $this->service()->getManagersForProperty($this->propertyId);

        $this->assertNotEmpty($team);
        $this->assertSame('owner', $team[0]['role']);
        $this->assertSame("owner-{$this->ownerId}@test.invalid", $team[0]['email']);
    }

    public function test_grant_by_email_creates_an_active_manager(): void
    {
        $result = $this->service()->grantManager($this->propertyId, $this->targetEmail, 'manager', $this->ownerId);

        $this->assertTrue($result['ok']);

        $managers = $this->managerRows($this->propertyId);
        $this->assertCount(1, $managers);
        $this->assertSame($this->targetEmail, $managers[0]['email']);
        $this->assertSame('Pat Manager', $managers[0]['name']);
        $this->assertSame('manager', $managers[0]['role']);
    }

    public function test_grant_fails_for_unknown_email(): void
    {
        $result = $this->service()->grantManager($this->propertyId, 'nobody@test.invalid', 'manager', $this->ownerId);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->managerRows($this->propertyId));
    }

    public function test_grant_fails_when_already_an_active_manager(): void
    {
        $this->service()->grantManager($this->propertyId, $this->targetEmail, 'manager', $this->ownerId);
        $second = $this->service()->grantManager($this->propertyId, $this->targetEmail, 'operator', $this->ownerId);

        $this->assertFalse($second['ok']);
        $this->assertCount(1, $this->managerRows($this->propertyId));
    }

    public function test_revoke_removes_the_manager_from_the_active_list(): void
    {
        $this->service()->grantManager($this->propertyId, $this->targetEmail, 'manager', $this->ownerId);
        $managerId = $this->managerRows($this->propertyId)[0]['id'];

        $this->assertTrue($this->service()->revokeManager($this->propertyId, $managerId));
        $this->assertSame([], $this->managerRows($this->propertyId));
    }

    public function test_revoke_scoped_to_another_property_is_a_no_op(): void
    {
        $this->service()->grantManager($this->propertyId, $this->targetEmail, 'manager', $this->ownerId);
        $managerId = $this->managerRows($this->propertyId)[0]['id'];

        // Same grant id, wrong property — must not revoke (cross-property guard).
        $this->assertFalse($this->service()->revokeManager($this->otherPropertyId, $managerId));
        $this->assertCount(1, $this->managerRows($this->propertyId));
    }
}
