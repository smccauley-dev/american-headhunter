<?php

namespace Tests\Feature\Api;

use App\Services\Wildlife\HarvestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mobile wildlife API — the HTTP surface over the Phase 6.2 services.
 *
 * DB 5 has no RLS, so the whole security story is the WildlifeAccess standing
 * check that each controller re-enforces. These tests prove the HTTP contract:
 * the auth + ability guards, standing (403 write / 404 read), the quota 409, the
 * offline-replay idempotency at the wire, CWD reference reads, and the trail
 * camera entitlement gate. The deeper quota/CWD/dedup logic is covered by the
 * service tests; here we prove the endpoints surface it.
 */
class WildlifeApiTest extends TestCase
{
    private string $lesseeId;

    private string $strangerId;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    private string $lesseeToken;

    private string $strangerToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesseeId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        $this->lesseeToken = $this->makeUser($this->lesseeId, 'lessee');
        $this->strangerToken = $this->makeUser($this->strangerId, 'stranger');

        // Active lease tying the lessee to the property.
        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId,
            'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type' => 'individual',
            'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId,
            'application_id' => $this->applicationId,
            'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->lesseeId,
            'lessor_user_id' => (string) Str::uuid(),
            'status' => 'active',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'total_price' => '3000.00',
            'deposit_paid' => '0.00',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->delete();
        DB::connection('wildlife')->table('harvest_quotas')->where('property_id', $this->propertyId)->delete();
        DB::connection('wildlife')->table('wildlife_sightings')->where('lease_id', $this->leaseId)->delete();
        DB::connection('wildlife')->table('fishing_harvest_logs')->where('lease_id', $this->leaseId)->delete();
        DB::connection('wildlife')->table('cwd_zones')->where('state_code', 'ZZ')->delete();

        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        foreach ([$this->lesseeId, $this->strangerId] as $uid) {
            DB::connection('identity')->table('personal_access_tokens')->where('tokenable_id', $uid)->delete();
            DB::connection('identity')->table('user_profiles')->where('user_id', $uid)->delete();
            DB::connection('identity')->table('users')->where('id', $uid)->delete();
        }

        foreach (['identity', 'lease', 'wildlife'] as $conn) {
            try {
                DB::connection($conn)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    private function makeUser(string $id, string $label): string
    {
        $password = 'WildlifeApi123!';
        $email = "{$label}-{$id}@example.com";

        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'account_type' => 'hunter',
            'status' => 'active',
            'trust_score' => 75,
            'is_veteran' => false,
            'failed_login_attempts' => 0,
        ]);
        DB::connection('identity')->table('user_profiles')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'first_name' => ucfirst($label),
            'last_name' => 'Tester',
        ]);

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('token');
    }

    /** @return array<string,mixed> */
    private function harvestPayload(array $overrides = []): array
    {
        return array_merge([
            'species_code' => 'whitetail_deer',
            'harvest_date' => '2026-11-15',
            'weapon_type' => 'bow',
        ], $overrides);
    }

    // ── Auth + ability guards ────────────────────────────────────────────────────

    public function test_harvest_write_requires_authentication(): void
    {
        $this->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload())
            ->assertStatus(401);
    }

    // ── Standing boundary (the whole DB-5 security story) ────────────────────────

    public function test_lessee_can_log_a_harvest(): void
    {
        $response = $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('harvest.species_code', 'whitetail_deer');
        $response->assertJsonPath('harvest.property_id', $this->propertyId);
    }

    public function test_stranger_without_standing_is_denied_403(): void
    {
        $this->withToken($this->strangerToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload())
            ->assertStatus(403);

        $this->assertSame(0, DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count());
    }

    public function test_owner_can_read_own_harvest(): void
    {
        $id = $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload())
            ->json('harvest.id');

        $this->withToken($this->lesseeToken)->getJson("/api/v1/harvests/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('harvest.id', $id);
    }

    public function test_show_denies_an_unrelated_reader_with_404(): void
    {
        // Seed the harvest out-of-band as the lessee, then read it as the
        // stranger. Only one token is exercised per test — Sanctum's request
        // guard memoizes the first-resolved user across requests in a test.
        $harvest = app(HarvestService::class)->log($this->lesseeId, $this->leaseId, $this->harvestPayload());

        $this->withToken($this->strangerToken)->getJson("/api/v1/harvests/{$harvest->id}")->assertStatus(404);
    }

    public function test_index_returns_the_callers_own_logs(): void
    {
        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload());

        $this->withToken($this->lesseeToken)->getJson('/api/v1/harvests')
            ->assertStatus(200)
            ->assertJsonCount(1, 'harvests');
    }

    public function test_index_excludes_other_users_logs(): void
    {
        // A harvest owned by the lessee must not appear for the stranger.
        app(HarvestService::class)->log($this->lesseeId, $this->leaseId, $this->harvestPayload());

        $this->withToken($this->strangerToken)->getJson('/api/v1/harvests')
            ->assertStatus(200)
            ->assertJsonCount(0, 'harvests');
    }

    // ── Quota (409) surfaced over HTTP ───────────────────────────────────────────

    public function test_full_quota_returns_409(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => 1,
            'current_harvest' => 0,
        ]);

        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload())
            ->assertStatus(201);

        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $this->harvestPayload())
            ->assertStatus(409);

        $this->assertSame(1, DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count());
    }

    public function test_quota_endpoint_reports_remaining(): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => 3,
            'current_harvest' => 1,
        ]);

        $this->withToken($this->lesseeToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/quota?year=2026")
            ->assertStatus(200)
            ->assertJsonPath('quotas.0.species_code', 'whitetail_deer')
            ->assertJsonPath('quotas.0.remaining', 2)
            ->assertJsonPath('quotas.0.scope', 'lease');
    }

    // ── Offline replay idempotency at the wire ───────────────────────────────────

    public function test_offline_replay_is_idempotent_and_returns_200_on_the_second_call(): void
    {
        $localId = (string) Str::uuid();
        $payload = $this->harvestPayload(['local_record_id' => $localId]);

        $first = $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $payload);
        $first->assertStatus(201);

        $second = $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/harvests", $payload);
        $second->assertStatus(200);

        $this->assertSame($first->json('harvest.id'), $second->json('harvest.id'));
        $this->assertSame(1, DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count());
    }

    // ── Sightings + fishing write paths ──────────────────────────────────────────

    public function test_lessee_can_log_and_list_a_sighting(): void
    {
        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/sightings", [
                'species_code' => 'turkey',
                'sighting_date' => '2026-11-10',
                'count' => 4,
            ])->assertStatus(201);

        $this->withToken($this->lesseeToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/sightings")
            ->assertStatus(200)
            ->assertJsonCount(1, 'sightings')
            ->assertJsonPath('sightings.0.count', 4);
    }

    public function test_stranger_cannot_log_a_sighting(): void
    {
        $this->withToken($this->strangerToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/sightings", [
                'species_code' => 'turkey',
                'sighting_date' => '2026-11-10',
            ])->assertStatus(403);
    }

    public function test_lessee_can_log_a_fishing_catch(): void
    {
        $this->withToken($this->lesseeToken)
            ->postJson("/api/v1/leases/{$this->leaseId}/fishing", [
                'species_code' => 'largemouth_bass',
                'catch_date' => '2026-07-01',
                'length_inches' => 18.5,
                'catch_and_release' => true,
            ])->assertStatus(201);

        $this->withToken($this->lesseeToken)
            ->getJson("/api/v1/leases/{$this->leaseId}/fishing")
            ->assertStatus(200)
            ->assertJsonCount(1, 'catches')
            ->assertJsonPath('catches.0.catch_and_release', true);
    }

    // ── CWD reference data ───────────────────────────────────────────────────────

    public function test_cwd_zones_returns_state_reference_data(): void
    {
        DB::connection('wildlife')->table('cwd_zones')->insert([
            'id' => (string) Str::uuid(),
            'state_code' => 'ZZ',
            'zone_name' => 'API Test Positive Zone',
            'zone_type' => 'positive',
            'effective_date' => '2026-01-01',
        ]);

        $this->withToken($this->lesseeToken)
            ->getJson('/api/v1/cwd/zones?state=ZZ')
            ->assertStatus(200)
            ->assertJsonPath('state', 'ZZ')
            ->assertJsonPath('zones.0.zone_type', 'positive')
            ->assertJsonPath('zones.0.requires_acknowledgment', true);
    }

    // ── Trail camera entitlement gate ────────────────────────────────────────────

    public function test_trail_cameras_are_gated_by_entitlement(): void
    {
        // No plan grants trail_camera_integration to these fixtures → 403.
        $this->withToken($this->lesseeToken)
            ->getJson("/api/v1/properties/{$this->propertyId}/cameras")
            ->assertStatus(403);
    }
}
