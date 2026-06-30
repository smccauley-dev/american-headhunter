<?php

namespace Tests\Feature\Member;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * In-platform signing is order-agnostic: when the hunter (lessee) signs first,
 * the landowner (lessor) must still be able to open the sign page and
 * countersign. The sign controller was scoped to the lessee alone, so the
 * landowner's countersign URL 404'd. This guards the party-scoped fix: the
 * landowner can load /sign and their (final) signature activates the lease, and
 * the lessee path keeps working.
 */
class LandownerCountersignTest extends TestCase
{
    private string $landownerId;
    private string $hunterId;
    private string $propertyId;
    private string $applicationId;
    private string $leaseId;
    private string $requestId;
    private string $lessorSignerId;
    private string $lesseeSignerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landownerId   = (string) Str::uuid();
        $this->hunterId      = (string) Str::uuid();
        $this->propertyId    = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->requestId     = (string) Str::uuid();
        $this->lessorSignerId = (string) Str::uuid();
        $this->lesseeSignerId = (string) Str::uuid();

        foreach ([[$this->landownerId, 'landowner', 'Owen', 'Owner'], [$this->hunterId, 'hunter', 'Hank', 'Hunter']] as [$id, $type, $first, $last]) {
            DB::connection('identity')->table('users')->insert([
                'id' => $id, 'email' => "countersign-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => $type,
            ]);
            DB::connection('identity')->table('user_profiles')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $id,
                'first_name' => $first, 'last_name' => $last,
            ]);
        }

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId, 'owner_user_id' => $this->landownerId,
            'title' => 'Boone County Hills', 'slug' => 'boone-'.Str::random(6),
            'status' => 'active', 'state_code' => 'MO', 'county' => 'Boone', 'total_acres' => '320.00',
        ]);

        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId, 'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->hunterId, 'application_type' => 'individual', 'status' => 'approved',
        ]);
        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId, 'application_id' => $this->applicationId, 'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->hunterId, 'lessor_user_id' => $this->landownerId,
            'status' => 'pending_signatures', 'start_date' => '2026-10-01', 'end_date' => '2026-11-30',
            'total_price' => '2500.00', 'deposit_paid' => '0.00',
        ]);

        // Primary lessee row (activateIfComplete approves it on completion).
        DB::connection('lease')->table('lease_hunters')->insert([
            'id' => (string) Str::uuid(), 'lease_id' => $this->leaseId,
            'user_id' => $this->hunterId, 'role' => 'primary', 'is_approved' => false,
        ]);

        // In-platform request (DB 11). Lessor pending, lessee already signed.
        DB::connection('documents')->table('esignature_requests')->insert([
            'id' => $this->requestId, 'lease_id' => $this->leaseId,
            'requester_user_id' => $this->landownerId, 'provider' => 'in_platform',
            'status' => 'out_for_signature', 'subject' => 'Hunting Lease Agreement — 2026',
            'requested_at' => now(),
        ]);
        DB::connection('documents')->table('esignature_signers')->insert([
            'id' => $this->lessorSignerId, 'request_id' => $this->requestId, 'user_id' => $this->landownerId,
            'email' => "countersign-landowner-{$this->landownerId}@test.invalid", 'name' => 'Owen Owner',
            'order_num' => 1, 'status' => 'pending',
        ]);
        DB::connection('documents')->table('esignature_signers')->insert([
            'id' => $this->lesseeSignerId, 'request_id' => $this->requestId, 'user_id' => $this->hunterId,
            'email' => "countersign-hunter-{$this->hunterId}@test.invalid", 'name' => 'Hank Hunter',
            'order_num' => 2, 'status' => 'signed', 'signed_at' => now()->subMinute(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('documents')->table('esignature_signers')->where('request_id', $this->requestId)->delete();
        DB::connection('documents')->table('esignature_requests')->where('id', $this->requestId)->delete();
        DB::connection('lease')->table('signature_events')->where('lease_id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_hunters')->where('lease_id', $this->leaseId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->landownerId, $this->hunterId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->landownerId, $this->hunterId])->delete();

        parent::tearDown();
    }

    public function test_landowner_can_load_the_sign_page_after_the_hunter_signed(): void
    {
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->get("/member/leases/{$this->leaseId}/sign")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Sign', false)
                ->where('lease.id', $this->leaseId)
                ->where('already_signed', false)
                // The landowner countersigns with no deposit step.
                ->where('deposit', null)
                ->has('signers', 2)
            );
    }

    public function test_landowner_countersignature_activates_the_lease(): void
    {
        $this->withSession(['auth.user_id' => $this->landownerId])
            ->post("/member/leases/{$this->leaseId}/sign", [
                'request_id' => $this->requestId,
                'full_name'  => 'Owen Owner',
                'agreed'     => true,
            ])
            ->assertRedirect(route('member.dashboard'));

        // Both signers signed, request completed, lease no longer pending.
        $this->assertSame('signed', DB::connection('documents')->table('esignature_signers')
            ->where('id', $this->lessorSignerId)->value('status'));
        $this->assertSame('completed', DB::connection('documents')->table('esignature_requests')
            ->where('id', $this->requestId)->value('status'));
        $this->assertNotSame('pending_signatures', DB::connection('lease')->table('leases')
            ->where('id', $this->leaseId)->value('status'));
    }

    public function test_lessee_can_still_load_the_sign_page(): void
    {
        $this->withSession(['auth.user_id' => $this->hunterId])
            ->get("/member/leases/{$this->leaseId}/sign")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Sign', false)
                ->where('lease.id', $this->leaseId)
                // The hunter already signed in this fixture.
                ->where('already_signed', true)
            );
    }
}
