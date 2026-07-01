<?php

namespace Tests\Feature\Member;

use App\Services\Wildlife\SightingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Member portal wildlife-sighting pages — the web sibling of the mobile API.
 *
 * DB 5 has no RLS, so the standing check inside SightingService is the whole
 * authorization story; these prove the web layer re-enforces it. Unlike harvest,
 * a sighting has no quota or CWD gate, so the store has nothing to translate —
 * the only thing that can go wrong is a standing failure, which is a genuine 403.
 */
class SightingLogTest extends TestCase
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
            'password_hash' => Hash::make('SightingWeb123!'),
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
            'sighting_date' => '2026-06-15',
            'count' => 3,
        ], $overrides);
    }

    private function rowCount(): int
    {
        return DB::connection('wildlife')->table('wildlife_sightings')->where('lease_id', $this->leaseId)->count();
    }

    // ── Auth ─────────────────────────────────────────────────────────────────────

    public function test_sighting_page_requires_authentication(): void
    {
        $this->get('/member/sightings')->assertRedirect();
    }

    // ── Standing boundary ────────────────────────────────────────────────────────

    public function test_lessee_can_log_a_sighting(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->post('/member/sightings', $this->payload())
            ->assertRedirect('/member/sightings')
            ->assertSessionHas('success');

        $this->assertSame(1, $this->rowCount());
    }

    public function test_stranger_without_standing_is_denied_403(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->post('/member/sightings', $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, $this->rowCount());
    }

    // ── Reads are caller-scoped by the service, not the DB ───────────────────────

    public function test_index_lists_only_the_callers_own_sightings(): void
    {
        app(SightingService::class)->log($this->lesseeId, $this->leaseId, $this->payload());

        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/sightings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Sighting/Index', false)
                ->has('sightings', 1)
                ->where('sightings.0.species', 'Whitetail Deer')
                ->where('sightings.0.count', 3));

        // The stranger, with no sightings of their own, sees an empty list.
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->get('/member/sightings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sightings', 0));
    }

    public function test_new_form_offers_the_active_lease(): void
    {
        $this->withSession(['auth.user_id' => $this->lesseeId])
            ->get('/member/sightings/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Sighting/New', false)
                ->has('leases', 1)
                ->where('leases.0.id', $this->leaseId));
    }
}
