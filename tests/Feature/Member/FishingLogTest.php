<?php

namespace Tests\Feature\Member;

use App\Services\Wildlife\FishingHarvestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Member portal fishing-log pages — the web sibling of the mobile API.
 *
 * Same standing boundary as harvest (the FishingHarvestService check is the whole
 * story on RLS-free DB 5), but with no quota or CWD gate, so a standing failure
 * is the only thing the store can hit — and it is a genuine 403.
 */
class FishingLogTest extends TestCase
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
        DB::connection('wildlife')->table('fishing_harvest_logs')->where('lease_id', $this->leaseId)->delete();

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
            'password_hash' => Hash::make('FishingWeb123!'),
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
            'species_code' => 'largemouth_bass',
            'catch_date' => '2026-06-15',
            'length_inches' => 18.5,
            'catch_and_release' => true,
        ], $overrides);
    }

    private function rowCount(): int
    {
        return DB::connection('wildlife')->table('fishing_harvest_logs')->where('lease_id', $this->leaseId)->count();
    }

    // ── Auth ─────────────────────────────────────────────────────────────────────

    public function test_fishing_page_requires_authentication(): void
    {
        $this->get('/member/fishing')->assertRedirect();
    }

    // ── Standing boundary ────────────────────────────────────────────────────────

    public function test_lessee_can_log_a_catch(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/fishing', $this->payload())
            ->assertRedirect('/member/fishing')
            ->assertSessionHas('success');

        $this->assertSame(1, $this->rowCount());
    }

    public function test_stranger_without_standing_is_denied_403(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->post('/member/fishing', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, $this->rowCount());
    }

    // ── Reads are caller-scoped by the service, not the DB ───────────────────────

    public function test_index_lists_only_the_callers_own_catches(): void
    {
        app(FishingHarvestService::class)->log($this->lesseeId, $this->leaseId, $this->payload());

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/fishing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Fishing/Index', false)
                ->has('catches', 1)
                ->where('catches.0.species', 'Largemouth Bass')
                ->where('catches.0.catch_and_release', true));

        // The stranger, with no catches of their own, sees an empty list.
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->get('/member/fishing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('catches', 0));
    }

    public function test_new_form_offers_the_active_lease(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/fishing/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Fishing/New', false)
                ->has('leases', 1)
                ->where('leases.0.id', $this->leaseId));
    }
}
