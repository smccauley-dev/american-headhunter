<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Isolation strategy:
 *   - property connection: committed immediately so property_read (readonly credentials)
 *     can see the fixtures; manually deleted in tearDown via cascade.
 *   - geospatial connection: committed immediately; manually deleted in tearDown.
 *   - Valkey cache keys for boundary are cleared in setUp/tearDown to prevent
 *     cross-test pollution when tests share a propertyId within the same run.
 */
class PropertyDetailTest extends TestCase
{
    private string $propertyId;
    private string $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->propertyId = (string) Str::uuid();
        $this->listingId  = (string) Str::uuid();

        Cache::store('valkey')->forget("geo:boundary:property:{$this->propertyId}");

        DB::connection('property')->table('properties')->insert([
            'id'             => $this->propertyId,
            'owner_user_id'  => (string) Str::uuid(),
            'title'          => 'Lone Star Hunting Lease',
            'slug'           => "test-property-{$this->propertyId}",
            'description'    => 'Prime whitetail habitat in Hill Country.',
            'status'         => 'active',
            'state_code'     => 'TX',
            'county'         => 'Gillespie',
            'total_acres'    => '500.00',
            'huntable_acres' => '450.00',
        ]);

        DB::connection('property')->table('property_species')->insert([
            'property_id'  => $this->propertyId,
            'species_code' => 'whitetail_deer',
            'is_primary'   => true,
        ]);

        DB::connection('property')->table('property_listings')->insert([
            'id'               => $this->listingId,
            'property_id'      => $this->propertyId,
            'listing_type'     => 'annual_lease',
            'status'           => 'active',
            'season_start'     => '2026-10-01',
            'season_end'       => '2026-11-30',
            'max_hunters'      => 4,
            'price_per_hunter' => '500.00',
            'visibility'       => 'public',
        ]);

        DB::connection('property')->table('property_rules')->insert([
            'property_id' => $this->propertyId,
            'rule_text'   => 'No hunting within 100 yards of the house.',
            'sort_order'  => 1,
        ]);
    }

    protected function tearDown(): void
    {
        // Listings have no FK cascade to properties, must delete explicitly
        DB::connection('property')->table('property_listings')->where('property_id', $this->propertyId)->delete();
        // property_species and property_rules cascade from properties — delete parent last
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        // Any boundary rows inserted by tests
        DB::connection('geospatial')->table('property_boundaries')->where('property_id', $this->propertyId)->delete();

        Cache::store('valkey')->forget("geo:boundary:property:{$this->propertyId}");

        // InjectDatabaseContext opens all 14 connections on every HTTP request.
        // Disconnect the full set after each method so the pool doesn't exhaust
        // across 11 test methods (each making 1-2 requests).
        foreach ([
            'property', 'property_read',
            'identity',
            'lease', 'billing',
            'wildlife', 'wildlife_read',
            'commerce', 'communications',
            'incidents', 'documents', 'platform',
            'geospatial', 'geospatial_read',
        ] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {}
        }

        parent::tearDown();
    }

    // ── Shape and field mapping ───────────────────────────────────────────────

    public function test_show_returns_property_detail_resource_shape(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id', 'title', 'slug', 'description', 'status',
            'state_code', 'county', 'total_acres', 'huntable_acres',
            'has_boundary', 'photos', 'species', 'amenities', 'rules', 'listings',
        ]);

        $response->assertJsonPath('id', $this->propertyId);
        $response->assertJsonPath('title', 'Lone Star Hunting Lease');
        $response->assertJsonPath('description', 'Prime whitetail habitat in Hill Country.');
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('state_code', 'TX');
        $response->assertJsonPath('county', 'Gillespie');
    }

    public function test_relations_are_mapped_correctly(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);

        // Species
        $response->assertJsonCount(1, 'species');
        $response->assertJsonPath('species.0.code', 'whitetail_deer');
        $response->assertJsonPath('species.0.is_primary', true);

        // Listing — active listing surfaced with correct pricing fields
        $response->assertJsonCount(1, 'listings');
        $response->assertJsonPath('listings.0.id', $this->listingId);
        $response->assertJsonPath('listings.0.listing_type', 'annual_lease');
        $response->assertJsonPath('listings.0.status', 'active');
        $response->assertJsonPath('listings.0.season_start', '2026-10-01');
        $response->assertJsonPath('listings.0.season_end', '2026-11-30');
        $response->assertJsonPath('listings.0.max_hunters', 4);
        $response->assertJsonPath('listings.0.price_per_hunter', '500.00');

        // Rule
        $response->assertJsonCount(1, 'rules');
        $response->assertJsonPath('rules.0.text', 'No hunting within 100 yards of the house.');
        $response->assertJsonPath('rules.0.sort_order', 1);

        // Relations loaded but empty
        $response->assertJsonPath('photos', []);
        $response->assertJsonPath('amenities', []);
    }

    // ── Owner/host internal fields ────────────────────────────────────────────

    public function test_no_owner_or_host_internals_in_response(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);

        $data = $response->json();

        $this->assertArrayNotHasKey('owner_user_id',   $data);
        $this->assertArrayNotHasKey('address_encrypted', $data);
        $this->assertArrayNotHasKey('deleted_at',       $data);
        $this->assertArrayNotHasKey('updated_at',       $data);

        // Listing internals must not leak through the explicit field map
        $this->assertArrayNotHasKey('property_id', $data['listings'][0] ?? []);
    }

    // ── has_boundary flag ─────────────────────────────────────────────────────

    public function test_has_boundary_false_when_boundary_geospatial_id_null(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);
        $response->assertJsonPath('has_boundary', false);
    }

    public function test_has_boundary_true_when_boundary_geospatial_id_set(): void
    {
        DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)
            ->update(['boundary_geospatial_id' => (string) Str::uuid()]);

        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);
        $response->assertJsonPath('has_boundary', true);
    }

    // ── No inline geo ─────────────────────────────────────────────────────────

    public function test_no_inline_boundary_geometry_in_detail_response(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayNotHasKey('geometry',    $data);
        $this->assertArrayNotHasKey('coordinates', $data);
        $this->assertArrayNotHasKey('boundary',    $data);
    }

    public function test_no_inline_geo_even_when_boundary_id_is_set(): void
    {
        DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)
            ->update(['boundary_geospatial_id' => (string) Str::uuid()]);

        $response = $this->getJson("/api/properties/{$this->propertyId}");
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayNotHasKey('geometry',              $data);
        $this->assertArrayNotHasKey('coordinates',           $data);
        $this->assertArrayNotHasKey('boundary',              $data);
        $this->assertArrayNotHasKey('boundary_geospatial_id', $data);
    }

    // ── /boundary endpoint — separate, untouched ──────────────────────────────

    public function test_boundary_endpoint_returns_404_when_no_boundary_exists(): void
    {
        $response = $this->getJson("/api/properties/{$this->propertyId}/boundary");
        $response->assertStatus(404);
        $response->assertJson(['error' => 'No boundary available']);
    }

    public function test_boundary_endpoint_returns_geojson_feature_when_boundary_exists(): void
    {
        // Clear any cached null from a previous test hitting the same propertyId/key
        Cache::store('valkey')->forget("geo:boundary:property:{$this->propertyId}");

        DB::connection('geospatial')->statement(
            "INSERT INTO property_boundaries (id, property_id, boundary, source)
             VALUES (gen_random_uuid(), ?, ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((-98.5 30.2,-98.6 30.2,-98.6 30.3,-98.5 30.3,-98.5 30.2)))'), 4326), 'manual')",
            [$this->propertyId]
        );

        $response = $this->getJson("/api/properties/{$this->propertyId}/boundary");
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'type',
            'geometry'   => ['type', 'coordinates'],
            'properties' => ['property_id', 'area_acres', 'source'],
        ]);
        $response->assertJsonPath('type', 'Feature');
        $response->assertJsonPath('geometry.type', 'MultiPolygon');
        $response->assertJsonPath('properties.property_id', $this->propertyId);
        $response->assertJsonPath('properties.source', 'manual');
    }

    // ── Visibility enforcement ────────────────────────────────────────────────

    public function test_draft_property_returns_404(): void
    {
        DB::connection('property')->table('properties')
            ->where('id', $this->propertyId)
            ->update(['status' => 'draft']);

        $this->getJson("/api/properties/{$this->propertyId}")->assertStatus(404);
    }

    public function test_unknown_property_uuid_returns_404(): void
    {
        $this->getJson('/api/properties/' . Str::uuid())->assertStatus(404);
    }
}
