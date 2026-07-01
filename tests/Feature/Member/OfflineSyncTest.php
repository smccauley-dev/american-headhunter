<?php

namespace Tests\Feature\Member;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The server contract behind the offline write queue (Phase 6.3d).
 *
 * The same member store endpoints serve two callers. A browser form post is an
 * Inertia request and gets a redirect. The offline flush replays a queued log with
 * `Accept: application/json`, and here the store must instead answer with a real
 * status code the flush can act on: 201 for a fresh insert, 200 for an idempotent
 * replay (dedup on the client-minted `local_record_id`, so a double-flush can never
 * create two rows or double-claim a quota), and 409 / 403 surfaced as genuine
 * statuses rather than the Inertia redirect a browser post would get.
 */
class OfflineSyncTest extends TestCase
{
    private string $lesseeId;

    private string $strangerId;

    private string $propertyId;

    private string $leaseId;

    private string $applicationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lesseeId = (string) Str::uuid();
        $this->strangerId = (string) Str::uuid();
        $this->propertyId = (string) Str::uuid();
        $this->leaseId = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        $this->makeUser($this->lesseeId, 'lessee');
        $this->makeUser($this->strangerId, 'stranger');

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

        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        foreach ([$this->lesseeId, $this->strangerId] as $uid) {
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

    private function makeUser(string $id, string $label): void
    {
        DB::connection('identity')->table('users')->insert([
            'id' => $id,
            'email' => "{$label}-{$id}@example.com",
            'password_hash' => Hash::make('OfflineSync123!'),
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
    }

    /** @return array<string,mixed> */
    private function harvestPayload(array $overrides = []): array
    {
        return array_merge([
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'weapon_type' => 'bow',
            'harvest_date' => '2026-06-15',
        ], $overrides);
    }

    private function seedQuota(int $max, int $current = 0): void
    {
        DB::connection('wildlife')->table('harvest_quotas')->insert([
            'id' => (string) Str::uuid(),
            'property_id' => $this->propertyId,
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'season_year' => 2026,
            'max_harvest' => $max,
            'current_harvest' => $current,
        ]);
    }

    private function harvestRows(): int
    {
        return DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count();
    }

    // ── A fresh flushed harvest is 201 ───────────────────────────────────────────

    public function test_json_store_returns_201_for_a_fresh_harvest(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/harvest', $this->harvestPayload(['local_record_id' => (string) Str::uuid()]))
            ->assertStatus(201)
            ->assertJsonStructure(['id']);

        $this->assertSame(1, $this->harvestRows());
    }

    // ── Replaying the same local_record_id is idempotent: 200, still one row ──────

    public function test_replayed_local_record_id_is_idempotent(): void
    {
        $localId = (string) Str::uuid();
        $payload = $this->harvestPayload(['local_record_id' => $localId]);

        $first = $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/harvest', $payload)
            ->assertStatus(201);

        $second = $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/harvest', $payload)
            ->assertStatus(200);

        // Same row returned, and the quota was never claimed twice.
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, $this->harvestRows());
    }

    // ── A full quota is a real 409 for the flush (not the Inertia redirect) ───────

    public function test_full_quota_is_a_409_json_status(): void
    {
        $this->seedQuota(max: 1);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/harvest', $this->harvestPayload(['local_record_id' => (string) Str::uuid()]))
            ->assertStatus(201);

        // A second, distinct offline harvest that finds the quota exhausted at sync.
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/harvest', $this->harvestPayload(['local_record_id' => (string) Str::uuid()]))
            ->assertStatus(409)
            ->assertJsonStructure(['message']);

        $this->assertSame(1, $this->harvestRows());
    }

    // ── No standing is a real 403 ────────────────────────────────────────────────

    public function test_stranger_flush_is_denied_403(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->postJson('/member/harvest', $this->harvestPayload(['local_record_id' => (string) Str::uuid()]))
            ->assertStatus(403);

        $this->assertSame(0, $this->harvestRows());
    }

    // ── The same JSON contract holds on the ungated sighting endpoint ────────────

    public function test_sighting_json_store_is_201_then_idempotent_200(): void
    {
        $localId = (string) Str::uuid();
        $payload = [
            'lease_id' => $this->leaseId,
            'species_code' => 'whitetail_deer',
            'sighting_date' => '2026-06-15',
            'count' => 3,
            'local_record_id' => $localId,
        ];

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/sightings', $payload)
            ->assertStatus(201);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->postJson('/member/sightings', $payload)
            ->assertStatus(200);

        $this->assertSame(
            1,
            DB::connection('wildlife')->table('wildlife_sightings')->where('lease_id', $this->leaseId)->count(),
        );
    }
}
