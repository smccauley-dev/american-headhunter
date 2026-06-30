<?php

namespace Tests\Feature\Property;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * SEC-060 — the public homepage must curate its featured-listings payload.
 *
 * HomeController previously returned raw PropertyListing/Property models, so the
 * unauthenticated `Home` Inertia prop serialized owner_user_id, precise
 * center_lat/center_lng, boundary_geospatial_id, and internal listing fields.
 * These assertions lock in the curated shape: only homepage-rendered fields are
 * present, owner/internal fields are gone, and coordinates are coarsened to the
 * ~1km (2-decimal) precision the hero card already displays.
 *
 * Fixtures are committed (featuredListings reads the property_read replica) and
 * removed in tearDown.
 */
class HomeFeaturedPayloadTest extends TestCase
{
    private string $propertyId;
    private string $ownerUserId;
    private string $boundaryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propertyId  = (string) Str::uuid();
        $this->ownerUserId = (string) Str::uuid();
        $this->boundaryId  = (string) Str::uuid();

        DB::connection('property')->table('properties')->insert([
            'id'                     => $this->propertyId,
            'owner_user_id'          => $this->ownerUserId,
            'title'                  => 'Featured Hill Country Tract',
            'slug'                   => "featured-{$this->propertyId}",
            'description'            => 'Prime whitetail habitat.',
            'status'                 => 'active',
            'state_code'             => 'TX',
            'county'                 => 'Gillespie',
            'total_acres'            => '500.00',
            // High-precision coordinates — the payload must expose only the
            // 2-decimal coarsening, never these exact values.
            'center_lat'             => 30.123456,
            'center_lng'             => -98.654321,
            'boundary_geospatial_id' => $this->boundaryId,
        ]);

        DB::connection('property')->table('property_species')->insert([
            'property_id'  => $this->propertyId,
            'species_code' => 'whitetail_deer',
            'is_primary'   => true,
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'               => (string) Str::uuid(),
            'property_id'      => $this->propertyId,
            'listing_type'     => 'annual_lease',
            'status'           => 'active',
            'season_start'     => '2026-10-01',
            'season_end'       => '2026-11-30',
            'max_hunters'      => 4,
            'price_per_hunter' => '500.00',
            'visibility'       => 'public',
            'is_featured'      => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('property')->table('property_listings')->where('property_id', $this->propertyId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        foreach (['property', 'property_read'] as $conn) {
            try { DB::connection($conn)->disconnect(); } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    public function test_homepage_payload_omits_owner_and_internal_fields_and_coarsens_coords(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Home', false)
            ->has('listings', fn (Assert $listings) => $listings
                ->where('0.property.id', $this->propertyId)
                // Curated fields the homepage card renders are present.
                ->where('0.property.title', 'Featured Hill Country Tract')
                ->where('0.property.state_code', 'TX')
                ->where('0.property.county', 'Gillespie')
                ->where('0.listing_type', 'annual_lease')
                ->has('0.property.species')
                // Owner identity + internal handles must NOT leak.
                ->missing('0.property.owner_user_id')
                ->missing('0.property.boundary_geospatial_id')
                ->missing('0.property.address_encrypted')
                ->missing('0.visibility')
                ->missing('0.early_termination_rent_policy')
                // Coordinates coarsened to 2 decimals — the precise value is gone.
                ->where('0.property.center_lat', 30.12)
                ->where('0.property.center_lng', -98.65)
                ->etc()
            )
            ->etc()
        );
    }
}
