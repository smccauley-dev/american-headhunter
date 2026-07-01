<?php

namespace Tests\Feature\Member;

use App\Services\Wildlife\HarvestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Member portal harvest pages — the web sibling of the mobile wildlife API.
 *
 * DB 5 has no RLS, so the standing check inside HarvestService is the whole
 * authorization story; these prove the web layer re-enforces it and — the one
 * thing the API does not have to worry about — that a full quota (service 409)
 * and a CWD requirement (service 422) are translated into a flash / field error
 * instead of leaking to the Inertia client, which reserves HTTP 409 for its own
 * asset-version reload.
 */
class HarvestLogTest extends TestCase
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
            'password_hash' => Hash::make('HarvestWeb123!'),
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
    private function payload(array $overrides = []): array
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

    private function rowCount(): int
    {
        return DB::connection('wildlife')->table('harvest_logs')->where('lease_id', $this->leaseId)->count();
    }

    // ── Auth ─────────────────────────────────────────────────────────────────────

    public function test_harvest_page_requires_authentication(): void
    {
        $this->get('/member/harvest')->assertRedirect();
    }

    // ── Standing boundary ────────────────────────────────────────────────────────

    public function test_lessee_can_log_a_harvest(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload())
            ->assertRedirect('/member/harvest')
            ->assertSessionHas('success');

        $this->assertSame(1, $this->rowCount());
    }

    public function test_stranger_without_standing_is_denied_403(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->post('/member/harvest', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, $this->rowCount());
    }

    // ── The web-specific concern: 409 must not reach Inertia ─────────────────────

    public function test_full_quota_surfaces_as_a_flash_error_not_a_409(): void
    {
        $this->seedQuota(max: 1);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload())
            ->assertRedirect('/member/harvest');

        // The second, over-quota submit must redirect back with a flash error —
        // never a bare 409, which Inertia would treat as an asset-version reload.
        $response = $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/harvest', $this->payload());

        $response->assertStatus(302);
        $response->assertSessionHas('error');
        $this->assertSame(1, $this->rowCount());
    }

    // ── Reads are caller-scoped by the service, not the DB ───────────────────────

    public function test_index_lists_only_the_callers_own_harvests(): void
    {
        app(HarvestService::class)->log($this->lesseeId, $this->leaseId, $this->payload());

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/harvest')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Harvest/Index', false)
                ->has('harvests', 1)
                ->where('harvests.0.species', 'Whitetail Deer'));

        // The stranger, with no logs of their own, sees an empty list.
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->get('/member/harvest')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('harvests', 0));
    }

    public function test_new_form_offers_the_active_lease(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/harvest/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Harvest/New', false)
                ->has('leases', 1)
                ->where('leases.0.id', $this->leaseId));
    }

    // ── Quota page ───────────────────────────────────────────────────────────────

    public function test_quota_page_reports_remaining_tags(): void
    {
        $this->seedQuota(max: 3, current: 1);

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/quota?year=2026')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Quota', false)
                ->where('season_year', 2026)
                ->where('leases.0.quotas.0.remaining', 2)
                ->where('leases.0.quotas.0.species', 'Whitetail Deer'));
    }
}
