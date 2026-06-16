<?php

namespace Tests\Feature\Property;

use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PropertyService::saveDetails / get*For — the member-portal "Property Details"
 * editing (game types, rules, amenities). Species and rules are full-replace
 * child tables; amenities sync the property_amenity_offerings pivot. These tests
 * pin that a save reflects exactly what was passed and that a second save
 * replaces (not appends) the prior state.
 */
class PropertyDetailsTest extends TestCase
{
    private string $propertyId;
    private string $amenityA;
    private string $amenityB;
    private string $amenityACategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propertyId = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'            => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title'         => 'Details Tract',
            'slug'          => "details-{$this->propertyId}",
            'status'        => 'draft',
            'state_code'    => 'TX',
            'county'        => 'Mason',
            'total_acres'   => '500.00',
        ]);

        // Use two seeded amenities (the table has a unique name constraint, so we
        // don't insert our own). One drives the catalog-grouping assertion.
        $amenities = DB::connection('property')->table('property_amenities')
            ->orderBy('category')->orderBy('name')->limit(2)->get();

        $this->assertGreaterThanOrEqual(2, $amenities->count(), 'Seed data must include amenities.');

        $this->amenityA         = $amenities[0]->id;
        $this->amenityACategory = $amenities[0]->category;
        $this->amenityB         = $amenities[1]->id;
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_amenity_offerings')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('property_species')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('property_rules')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    private function service(): PropertyService
    {
        return app(PropertyService::class);
    }

    public function test_save_details_persists_species_rules_and_amenities(): void
    {
        $this->service()->saveDetails(
            $this->propertyId,
            [['species_code' => 'whitetail_deer', 'is_primary' => true], ['species_code' => 'turkey', 'is_primary' => false]],
            [['rule_text' => 'Sign the gate log'], ['rule_text' => 'No ATVs after dark']],
            [$this->amenityA, $this->amenityB],
        );

        $species = collect($this->service()->getSpeciesFor($this->propertyId));
        $this->assertCount(2, $species);
        $this->assertTrue($species->firstWhere('species_code', 'whitetail_deer')['is_primary']);

        $rules = $this->service()->getRulesFor($this->propertyId);
        $this->assertSame(['Sign the gate log', 'No ATVs after dark'], array_column($rules, 'rule_text'));

        $this->assertEqualsCanonicalizing(
            [$this->amenityA, $this->amenityB],
            $this->service()->getAmenityIdsFor($this->propertyId),
        );
    }

    public function test_save_details_replaces_rather_than_appends(): void
    {
        $this->service()->saveDetails(
            $this->propertyId,
            [['species_code' => 'hog', 'is_primary' => false]],
            [['rule_text' => 'First rule']],
            [$this->amenityA],
        );

        // Second save with a smaller set must replace the first entirely.
        $this->service()->saveDetails(
            $this->propertyId,
            [],
            [['rule_text' => 'Only rule']],
            [],
        );

        $this->assertCount(0, $this->service()->getSpeciesFor($this->propertyId));
        $this->assertSame(['Only rule'], array_column($this->service()->getRulesFor($this->propertyId), 'rule_text'));
        $this->assertSame([], $this->service()->getAmenityIdsFor($this->propertyId));
    }

    public function test_amenity_catalog_groups_by_category(): void
    {
        $catalog = collect($this->service()->getAmenityCatalog());

        $group = $catalog->firstWhere('category', $this->amenityACategory);
        $this->assertNotNull($group);
        $this->assertSame(\App\Models\Property\PropertyAmenity::categoryLabel($this->amenityACategory), $group['label']);
        $this->assertContains($this->amenityA, array_column($group['items'], 'id'));
    }
}
