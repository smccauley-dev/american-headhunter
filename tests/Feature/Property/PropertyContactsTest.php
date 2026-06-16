<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService contacts surface — the member-portal Contacts tab (Slice 6).
 * Two kinds of contact: managers promoted to field contacts (a toggle on
 * property_managers.is_field_contact) and standalone emergency/local
 * PropertyContact rows. These tests pin phone digit-cleaning, the property-scoped
 * CRUD (a foreign id is a no-op, never a cross-property write), the is_field_contact
 * toggle, and that getContactDirectory only surfaces opted-in managers.
 */
class PropertyContactsTest extends TestCase
{
    private string $ownerId;
    private string $managerUserId;
    private string $propertyId;
    private string $otherPropertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId         = (string) Str::uuid();
        $this->managerUserId   = (string) Str::uuid();
        $this->propertyId      = (string) Str::uuid();
        $this->otherPropertyId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->ownerId,
            'email'         => "owner-{$this->ownerId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'landowner',
        ]);
        DB::connection('identity')->table('users')->insert([
            'id'            => $this->managerUserId,
            'email'         => "mgr-{$this->managerUserId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $this->managerUserId,
            'first_name' => 'Dana',
            'last_name'  => 'Keeper',
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
        DB::connection('property')->table('property_contacts')->whereIn('property_id', [$this->propertyId, $this->otherPropertyId])->delete();
        DB::connection('property')->table('property_managers')->whereIn('property_id', [$this->propertyId, $this->otherPropertyId])->delete();
        DB::connection('property')->table('properties')->whereIn('id', [$this->propertyId, $this->otherPropertyId])->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->managerUserId)->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->ownerId, $this->managerUserId])->delete();

        foreach (['property', 'property_read', 'identity'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    /** Grant a manager role; returns the property_managers row id. */
    private function grantManager(string $propertyId, bool $isFieldContact = false): string
    {
        $id = (string) Str::uuid();
        DB::connection('property')->table('property_managers')->insert([
            'id'                 => $id,
            'property_id'        => $propertyId,
            'user_id'            => $this->managerUserId,
            'role'               => 'manager',
            'granted_by_user_id' => $this->ownerId,
            'granted_at'         => now(),
            'is_field_contact'   => $isFieldContact,
        ]);

        return $id;
    }

    // ── Emergency / local contacts ──────────────────────────────────────────────

    public function test_add_contact_cleans_phone_to_digits(): void
    {
        $this->service()->addContact($this->propertyId, [
            'contact_type' => 'game_warden',
            'name'         => 'Llano County Warden',
            'phone'        => '(555) 123-4567',
        ]);

        $contacts = $this->service()->getEditableContacts($this->propertyId);
        $this->assertCount(1, $contacts);
        $this->assertSame('5551234567', $contacts[0]['phone']);
        $this->assertSame('game_warden', $contacts[0]['contact_type']);
    }

    public function test_update_contact_scoped_to_another_property_is_a_no_op(): void
    {
        $this->service()->addContact($this->propertyId, ['contact_type' => 'emergency', 'name' => 'Hospital']);
        $contactId = $this->service()->getEditableContacts($this->propertyId)[0]['id'];

        // Same contact id, wrong property — must not update.
        $ok = $this->service()->updateContact($this->otherPropertyId, $contactId, ['contact_type' => 'other', 'label' => 'Hijacked']);

        $this->assertFalse($ok);
        $this->assertSame('emergency', $this->service()->getEditableContacts($this->propertyId)[0]['contact_type']);
    }

    public function test_delete_contact_soft_deletes_and_is_property_scoped(): void
    {
        $this->service()->addContact($this->propertyId, ['contact_type' => 'law_enforcement', 'name' => 'Sheriff']);
        $contactId = $this->service()->getEditableContacts($this->propertyId)[0]['id'];

        // Wrong property — no-op.
        $this->assertFalse($this->service()->deleteContact($this->otherPropertyId, $contactId));
        $this->assertCount(1, $this->service()->getEditableContacts($this->propertyId));

        // Correct property — soft-deleted, drops out of the list.
        $this->assertTrue($this->service()->deleteContact($this->propertyId, $contactId));
        $this->assertSame([], $this->service()->getEditableContacts($this->propertyId));
    }

    // ── Manager field contacts ──────────────────────────────────────────────────

    public function test_add_manager_contact_toggles_field_contact_and_surfaces_in_directory(): void
    {
        $this->grantManager($this->propertyId, isFieldContact: false);

        // Eligible to add (not yet a field contact), and absent from the directory.
        $this->assertCount(1, $this->service()->getEligibleManagerContacts($this->propertyId));
        $this->assertSame([], $this->service()->getContactDirectory($this->propertyId, includeManagerIds: true)['managers']);

        $this->assertTrue($this->service()->addManagerContact($this->propertyId, $this->grantedId($this->propertyId)));

        // Now in the directory with a manager_id, and no longer eligible to add.
        $directory = $this->service()->getContactDirectory($this->propertyId, includeManagerIds: true);
        $this->assertCount(1, $directory['managers']);
        $this->assertSame('Dana Keeper', $directory['managers'][0]['name']);
        $this->assertArrayHasKey('manager_id', $directory['managers'][0]);
        $this->assertSame([], $this->service()->getEligibleManagerContacts($this->propertyId));
    }

    public function test_remove_manager_contact_clears_field_contact(): void
    {
        $managerId = $this->grantManager($this->propertyId, isFieldContact: true);

        $this->assertTrue($this->service()->removeManagerContact($this->propertyId, $managerId));

        $this->assertSame([], $this->service()->getContactDirectory($this->propertyId, includeManagerIds: true)['managers']);
        $this->assertCount(1, $this->service()->getEligibleManagerContacts($this->propertyId));
    }

    public function test_add_manager_contact_scoped_to_another_property_is_a_no_op(): void
    {
        $managerId = $this->grantManager($this->propertyId, isFieldContact: false);

        $this->assertFalse($this->service()->addManagerContact($this->otherPropertyId, $managerId));
        $this->assertSame([], $this->service()->getContactDirectory($this->propertyId, includeManagerIds: true)['managers']);
    }

    /** The most recent grant id on a property. */
    private function grantedId(string $propertyId): string
    {
        return DB::connection('property')->table('property_managers')
            ->where('property_id', $propertyId)->value('id');
    }
}
