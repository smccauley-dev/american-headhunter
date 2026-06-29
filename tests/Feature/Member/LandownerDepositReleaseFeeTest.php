<?php

namespace Tests\Feature\Member;

use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\FeeService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Refund;
use Stripe\Transfer;
use Tests\TestCase;

/**
 * On a clean security-deposit release the platform refunds the hunter in full and
 * Stripe keeps its non-refundable processing fee. That fee is the landowner's cost
 * (fee_schedules category=security_deposit, payer=landowner), so release() recovers
 * it best-effort by debiting the landowner's Connect balance:
 *   - landowner has a chargeable Connect account → the fee is debited and the row is
 *     marked release_fee_status='collected' with the transfer id;
 *   - no chargeable account (or the debit fails) → recorded 'deferred' (owed), and
 *     the release still succeeds (the hunter's refund must never be blocked by it).
 *
 * A WY-scoped fee rule keeps the math deterministic and isolated from dev data.
 * Lease/property/deposit rows are real (owner role in tests bypasses RLS); Stripe is
 * mocked.
 */
class LandownerDepositReleaseFeeTest extends TestCase
{
    private string $hunterId;
    private string $landownerId;
    private string $leaseId;
    private string $applicationId;
    private string $propertyId;
    private string $depositId;
    private string $feeScheduleId;
    private ?string $stripeAccountRowId = null;

    private const STATE   = 'WY';
    private const ACCOUNT = 'acct_testWY123';
    private const FEE     = 1480; // round(50000 * 2.9%) + 30¢

    protected function setUp(): void
    {
        parent::setUp();

        $this->hunterId      = (string) Str::uuid();
        $this->landownerId   = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->applicationId = (string) Str::uuid();
        $this->propertyId    = (string) Str::uuid();

        foreach ([[$this->hunterId, 'hunter'], [$this->landownerId, 'landowner']] as [$id, $type]) {
            DB::connection('identity')->table('users')->insert([
                'id' => $id, 'email' => "relfee-{$type}-{$id}@test.invalid",
                'password_hash' => 'test-hash', 'status' => 'active', 'account_type' => $type,
            ]);
            DB::connection('identity')->table('user_profiles')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $id,
                'first_name' => 'RelFee', 'last_name' => ucfirst($type),
            ]);
        }

        DB::connection('property')->table('properties')->insert([
            'id' => $this->propertyId, 'owner_user_id' => $this->landownerId,
            'title' => 'Wind River Ranch', 'slug' => 'wind-river-'.Str::random(6),
            'status' => 'active', 'state_code' => self::STATE, 'county' => 'Fremont', 'total_acres' => '320.00',
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

        // Deterministic, isolated landowner-borne processing fee for this state.
        DB::connection('billing')->table('fee_schedules')
            ->where('transaction_category', 'security_deposit')->where('state_code', self::STATE)->delete();
        $this->feeScheduleId = (string) Str::uuid();
        DB::connection('billing')->table('fee_schedules')->insert([
            'id' => $this->feeScheduleId, 'transaction_category' => 'security_deposit',
            'state_code' => self::STATE, 'pct' => '2.9000', 'flat_cents' => 30, 'payer' => 'landowner',
            'is_active' => true, 'effective_from' => now()->subDay(),
        ]);
        app(FeeService::class)->flushCache();

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
        DB::connection('billing')->table('fee_schedules')->where('id', $this->feeScheduleId)->delete();
        if ($this->stripeAccountRowId) {
            DB::connection('billing')->table('stripe_accounts')->where('id', $this->stripeAccountRowId)->delete();
        }
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();
        DB::connection('property')->table('properties')->where('id', $this->propertyId)->delete();
        DB::connection('identity')->table('user_profiles')->whereIn('user_id', [$this->hunterId, $this->landownerId])->delete();
        DB::connection('identity')->table('users')->whereIn('id', [$this->hunterId, $this->landownerId])->delete();
        app(FeeService::class)->flushCache();

        parent::tearDown();
    }

    private function giveLandownerChargeableAccount(): void
    {
        $this->stripeAccountRowId = (string) Str::uuid();
        DB::connection('billing')->table('stripe_accounts')->insert([
            'id' => $this->stripeAccountRowId, 'user_id' => $this->landownerId,
            'stripe_account_id' => self::ACCOUNT, 'charges_enabled' => true,
            'payouts_enabled' => true, 'details_submitted' => true,
        ]);
    }

    public function test_release_debits_the_fee_from_the_landowners_connect_balance(): void
    {
        $this->giveLandownerChargeableAccount();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()->with(Mockery::any(), 50000, Mockery::any())
                ->andReturn(Refund::constructFrom(['id' => 're_release']));
            $mock->shouldReceive('debitConnectedAccount')
                ->once()->with(self::ACCOUNT, self::FEE, Mockery::type('array'))
                ->andReturn(Transfer::constructFrom(['id' => 'tr_fee']));
        });

        app(SecurityDepositService::class)->release($this->depositId, $this->landownerId);

        $deposit = SecurityDeposit::find($this->depositId);
        $this->assertSame('released', $deposit->status);
        $this->assertSame(50000, $deposit->refunded_amount_cents);
        $this->assertSame(self::FEE, $deposit->release_fee_cents);
        $this->assertSame('collected', $deposit->release_fee_status);
        $this->assertSame('tr_fee', $deposit->release_fee_transfer_id);
    }

    public function test_release_defers_the_fee_when_landowner_has_no_chargeable_account(): void
    {
        // No stripe_accounts row for the landowner — the debit can't happen.
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('refundPaymentIntent')
                ->once()->andReturn(Refund::constructFrom(['id' => 're_release']));
            $mock->shouldNotReceive('debitConnectedAccount');
        });

        app(SecurityDepositService::class)->release($this->depositId, $this->landownerId);

        $deposit = SecurityDeposit::find($this->depositId);
        $this->assertSame('released', $deposit->status, 'the release must still succeed');
        $this->assertSame(50000, $deposit->refunded_amount_cents);
        $this->assertSame(self::FEE, $deposit->release_fee_cents);
        $this->assertSame('deferred', $deposit->release_fee_status);
        $this->assertNull($deposit->release_fee_transfer_id);
    }
}
