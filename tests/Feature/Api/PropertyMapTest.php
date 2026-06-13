<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mobile field-ops map API — GET /api/v1/properties/{id}/map(-images).
 *
 * The security surface is the lease gate: map markers carry precise on-property
 * GPS (SEC-024), so only a party to an active lease on the property may read
 * them. Everyone else gets 404 (never 403 — no existence disclosure).
 */
class PropertyMapTest extends TestCase
{
    private string $userId;

    private string $bearerToken;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    private string $mapImageId;

    private string $boundaryDocId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->mapImageId = (string) Str::uuid();
        $this->boundaryDocId = (string) Str::uuid();

        $password = 'MapTest123!';

        DB::connection('identity')->table('users')->insert([
            'id' => $this->userId,
            'email' => "lessee-map-{$this->userId}@example.com",
            'password_hash' => Hash::make($password),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->userId,
            'first_name' => 'Lessee',
            'last_name' => 'Mapper',
        ]);

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId,
            'owner_user_id' => (string) Str::uuid(),
            'title' => 'Map Test Ranch',
            'slug' => "map-test-{$this->propertyId}",
            'status' => 'active',
            'state_code' => 'TX',
            'county' => 'Llano',
            'total_acres' => '320.00',
        ]);

        DB::connection('property')->table('property_map_images')->insert([
            'id' => $this->mapImageId,
            'property_id' => $this->propertyId,
            'document_id' => $this->boundaryDocId,
            'sort_order' => 0,
            'is_boundary' => true,
            'latitude' => '30.751000',
            'longitude' => '-98.675000',
        ]);

        DB::connection('property')->table('property_map_markers')->insert([
            'map_image_id' => $this->mapImageId,
            'label' => 'North Box Blind',
            'marker_type' => 'stand',
            'x_percent' => '42.500',
            'y_percent' => '31.000',
            'latitude' => '30.752100',
            'longitude' => '-98.674200',
            'notes' => 'Faces the food plot',
        ]);

        // Active lease tying the user to the property as lessee.
        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->userId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->userId,
            'lessor_user_id' => (string) Str::uuid(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'total_price' => '4000.00',
            'deposit_paid' => '0.00',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => "lessee-map-{$this->userId}@example.com",
            'password' => $password,
        ]);
        $this->bearerToken = $login->json('token');
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        // markers cascade from map_images; map_images cascade from properties
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();

        DB::connection('identity')->table('personal_access_tokens')->where('tokenable_id', $this->userId)->delete();
        DB::connection('identity')->table('user_profiles')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        foreach (['identity', 'lease', 'property', 'property_read', 'documents'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_map_requires_authentication(): void
    {
        $this->getJson("/api/v1/properties/{$this->propertyId}/map")->assertStatus(401);
    }

    // ── Active lessee — happy path ──────────────────────────────────────────────

    public function test_active_lessee_receives_images_and_markers(): void
    {
        $response = $this->withToken($this->bearerToken)
            ->getJson("/api/v1/properties/{$this->propertyId}/map");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'property_id',
                'images' => [[
                    'id', 'image_url', 'is_boundary', 'description', 'sort_order',
                    'reference_point',
                    'markers' => [['id', 'label', 'type', 'type_label', 'color', 'x_percent', 'y_percent', 'lat', 'lng', 'notes']],
                ]],
            ],
        ]);

        $response->assertJsonPath('data.property_id', $this->propertyId);
        $response->assertJsonPath('data.images.0.is_boundary', true);
        $response->assertJsonPath('data.images.0.markers.0.label', 'North Box Blind');
        $response->assertJsonPath('data.images.0.markers.0.type', 'stand');
        $response->assertJsonPath('data.images.0.markers.0.type_label', 'Stand / Blind');
    }

    // ── Lease gate ──────────────────────────────────────────────────────────────

    public function test_non_lessee_gets_404(): void
    {
        // Authenticated, but no lease on this property: detach the lease.
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();

        $this->withToken($this->bearerToken)
            ->getJson("/api/v1/properties/{$this->propertyId}/map")
            ->assertStatus(404);
    }

    public function test_non_active_lease_does_not_grant_access(): void
    {
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)
            ->update(['status' => 'pending_signatures']);

        $this->withToken($this->bearerToken)
            ->getJson("/api/v1/properties/{$this->propertyId}/map")
            ->assertStatus(404);
    }

    // ── Image route authz ───────────────────────────────────────────────────────

    public function test_image_route_404_for_unknown_document(): void
    {
        // Active lessee, but a document that isn't a map image of this property.
        $this->withToken($this->bearerToken)
            ->get("/api/v1/properties/{$this->propertyId}/map-images/".Str::uuid())
            ->assertStatus(404);
    }

    public function test_image_route_404_for_non_lessee(): void
    {
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();

        $this->withToken($this->bearerToken)
            ->get("/api/v1/properties/{$this->propertyId}/map-images/{$this->boundaryDocId}")
            ->assertStatus(404);
    }
}
