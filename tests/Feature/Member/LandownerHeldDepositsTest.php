<?php

namespace Tests\Feature\Member;

use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\SecurityDepositService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The landowner-profile discoverability surface: heldSummariesForLandowner returns
 * one row per held deposit on a lease where the user is the lessor (payee), carrying
 * the property title, remaining amount, lease status, claim flag, and a link to the
 * lease-detail page where the release/forfeit controls actually live. Rows are real
 * (owner role in tests bypasses RLS); names are resolved cross-DB in the service.
 */
class LandownerHeldDepositsTest extends TestCase
{
    private string $landownerId;
    private string $hunterId;
    private string $leaseId;
    private string $propertyId;
    private string $applicationId;
    private string $depositId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landownerId = (string) Str::uuid();
        $this->hunterId    = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->propertyId    = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();

        foreach ([[$this->landownerId, 'landowner'], [$this->hunterId, 'hunter']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id' => $id, 'email' => "held-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => $type,
            ]);
            DB::connection('identity')->table('user_profiles')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $id,
                'first_name' => 'Held', 'last_name' => ucfirst($type),
            ]);
        }

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId, 'owner_user_id' => $this->landownerId,
            'title' => 'Cedar Bluff Ranch', 'slug' => 'cedar-bluff-'.Str::random(6),
            'status' => 'active', 'state_code' => 'TX', 'county' => 'Kerr', 'total_acres' => '640.00',
        ]);

        DB::connection('lease')->table('lease_applications')->insert([
            'id' => $this->applicationId, 'listing_id' => (string) Str::uuid(),
            'applicant_user_id' => $this->hunterId, 'application_type' => 'individual', 'status' => 'approved',
        ]);

        DB::connection('lease')->table('leases')->insert([
            'id' => $this->leaseId, 'application_id' => $this->applicationId, 'property_id' => $this->propertyId,
            'listing_id' => (string) Str::uuid(),
            'lessee_user_id' => $this->hunterId, 'lessor_user_id' => $this->landownerId,
            'status' => 'terminated', 'start_date' => '2026-10-01', 'end_date' => '2026-11-30',
            'total_price' => '2500.00', 'deposit_paid' => '500.00',
        ]);

        $deposit = SecurityDeposit::create([
            'lease_id' => $this->leaseId, 'payer_user_id' => $this->hunterId,
            'payee_user_id' => $this->landownerId, 'amount_cents' => 50000, 'currency' => 'USD',
            'status' => 'held', 'stripe_payment_intent_id' => 'pi_'.Str::random(12), 'held_at' => now(),
        ]);
        $this->depositId = $deposit->id;
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('security_deposits')->where('id', $this->depositId)->delete();
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->landownerId, $this->hunterId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->landownerId, $this->hunterId])->delete();

        parent::tearDown();
    }

    public function test_held_summary_lists_the_landowners_held_deposit_with_property_and_link(): void
    {
        $rows = app(SecurityDepositService::class)->heldSummariesForLandowner($this->landownerId);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($this->leaseId, $row['lease_id']);
        $this->assertSame('Cedar Bluff Ranch', $row['property_name']);
        $this->assertSame('500.00', $row['amount']);
        $this->assertSame('terminated', $row['lease_status']);
        $this->assertFalse($row['has_claim']);
        $this->assertStringContainsString("/member/leases/{$this->leaseId}", $row['url']);
    }

    public function test_a_released_deposit_is_not_listed(): void
    {
        $deposit = SecurityDeposit::find($this->depositId);
        $deposit->status = 'released';
        $deposit->refunded_amount_cents = 50000;
        $deposit->save();

        $this->assertSame([], app(SecurityDepositService::class)->heldSummariesForLandowner($this->landownerId));
    }

    public function test_another_landowner_sees_nothing(): void
    {
        $this->assertSame([], app(SecurityDepositService::class)->heldSummariesForLandowner((string) Str::uuid()));
    }
}
