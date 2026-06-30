<?php

namespace Tests\Feature\Member;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The property hub's "Leases" list: a landowner managing a property sees every
 * lease ever written against it — past and current — newest first, with the
 * lessee name resolved cross-DB. Authorization is service-layer
 * (userCanManageProperty); a non-manager gets a 404.
 */
class PropertyLeasesIndexTest extends TestCase
{
    private string $landownerId;
    private string $hunterId;
    private string $strangerId;
    private string $propertyId;
    private string $activeLeaseId;
    private string $terminatedLeaseId;
    private array $applicationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->landownerId = (string) Str::uuid();
        $this->hunterId    = (string) Str::uuid();
        $this->strangerId  = (string) Str::uuid();
        $this->propertyId  = (string) Str::uuid();
        $this->activeLeaseId     = (string) Str::uuid();
        $this->terminatedLeaseId = (string) Str::uuid();

        foreach ([[$this->landownerId, 'landowner', 'Owen', 'Owner'], [$this->hunterId, 'hunter', 'Hank', 'Hunter'], [$this->strangerId, 'landowner', 'Stan', 'Stranger']] as [$id, $type, $first, $last]) {
            DB::connection('identity')->table('users')->insert([
                'id' => $id, 'email' => "lease-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => $type,
            ]);
            DB::connection('identity')->table('user_profiles')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $id,
                'first_name' => $first, 'last_name' => $last,
            ]);
        }

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId, 'owner_user_id' => $this->landownerId,
            'title' => 'Cedar Bluff Ranch', 'slug' => 'cedar-bluff-'.Str::random(6),
            'status' => 'active', 'state_code' => 'TX', 'county' => 'Kerr', 'total_acres' => '640.00',
        ]);

        // Two leases on the property — one terminated (older), one active (newer).
        foreach ([
            [$this->terminatedLeaseId, 'terminated', '2026-01-01', '2026-02-01', now()->subDays(5)],
            [$this->activeLeaseId, 'active', '2026-10-01', '2026-11-30', now()->subDay()],
        ] as [$leaseId, $status, $start, $end, $createdAt]) {
            $appId = (string) Str::uuid();
            $this->applicationIds[] = $appId;
            DB::connection('lease')->table('lease_applications')->insert([
                'id' => $appId, 'listing_id' => (string) Str::uuid(),
                'applicant_user_id' => $this->hunterId, 'application_type' => 'individual', 'status' => 'approved',
            ]);
            DB::connection('lease')->table('leases')->insert([
                'id' => $leaseId, 'application_id' => $appId, 'property_id' => $this->propertyId,
                'listing_id' => (string) Str::uuid(),
                'lessee_user_id' => $this->hunterId, 'lessor_user_id' => $this->landownerId,
                'status' => $status, 'start_date' => $start, 'end_date' => $end,
                'total_price' => '2500.00', 'deposit_paid' => '0.00',
                'terminated_at' => $status === 'terminated' ? now()->subDays(4) : null,
                'created_at' => $createdAt,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::connection('lease')->table('leases')->whereIn('id', [$this->activeLeaseId, $this->terminatedLeaseId])->delete();
        DB::connection('lease')->table('lease_applications')->whereIn('id', $this->applicationIds)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->landownerId, $this->hunterId, $this->strangerId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->landownerId, $this->hunterId, $this->strangerId])->delete();

        parent::tearDown();
    }

    public function test_landowner_sees_all_leases_newest_first(): void
    {
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->get("/member/properties/{$this->propertyId}/leases")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Properties/Leases/Index', false)
                ->where('property.id', $this->propertyId)
                ->has('leases', 2)
                // Newest (active) first.
                ->where('leases.0.id', $this->activeLeaseId)
                ->where('leases.0.status', 'active')
                ->where('leases.0.status_label', 'Active')
                ->where('leases.0.lessee_name', 'Hank Hunter')
                ->where('leases.0.total_price', 2500)
                ->where('leases.1.id', $this->terminatedLeaseId)
                ->where('leases.1.status', 'terminated')
                ->where('leases.1.status_label', 'Terminated')
            );
    }

    public function test_a_non_manager_is_denied(): void
    {
        $this->withSession(['auth.user_id' => $this->strangerId])
            ->get("/member/properties/{$this->propertyId}/leases")
            ->assertNotFound();
    }
}
