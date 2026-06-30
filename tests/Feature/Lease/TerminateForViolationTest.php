<?php

namespace Tests\Feature\Lease;

use App\Models\Billing\LeasePayment;
use App\Models\Billing\SecurityDeposit;
use App\Services\Billing\StripeService;
use App\Services\Lease\LeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

/**
 * LeaseService::terminateForViolation — a landowner ends an active lease for the
 * hunter's breach. Two money consequences: the security deposit is forfeited as a
 * contestable FAULT_LESSEE claim (money stays held), and prepaid rent is
 * forfeited/refunded per the lease's snapshotted policy (overridable).
 *
 * Real rows on the lease/billing connections (tests run as owner → RLS bypassed);
 * Stripe is mocked so the rent refund never leaves the box.
 */
class TerminateForViolationTest extends TestCase
{
    private string $applicationId;
    private string $leaseId;
    private string $lesseeId;
    private string $lessorId;
    /** @var array<int,string> */ private array $depositIds = [];
    /** @var array<int,string> */ private array $paymentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationId = (string) Str::uuid();
        $this->leaseId       = (string) Str::uuid();
        $this->lesseeId      = (string) Str::uuid();
        $this->lessorId      = (string) Str::uuid();

        DB::connection('lease')->table('lease_applications')->insert([
            'id'                => $this->applicationId,
            'listing_id'        => (string) Str::uuid(),
            'applicant_user_id' => $this->lesseeId,
            'application_type'  => 'individual',
            'status'            => 'approved',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->paymentIds) {
            DB::connection('billing')->table('lease_payments')->whereIn('id', $this->paymentIds)->delete();
        }
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->delete();
        DB::connection('lease')->table('lease_applications')->where('id', $this->applicationId)->delete();

        parent::tearDown();
    }

    /** Seed an active lease with the given term and snapshotted rent policy. */
    private function seedActiveLease(string $policy, string $start, string $end): void
    {
        DB::connection('lease')->table('leases')->insert([
            'id'                            => $this->leaseId,
            'application_id'                => $this->applicationId,
            'property_id'                   => (string) Str::uuid(),
            'listing_id'                    => (string) Str::uuid(),
            'lessee_user_id'                => $this->lesseeId,
            'lessor_user_id'                => $this->lessorId,
            'status'                        => 'active',
            'start_date'                    => $start,
            'end_date'                      => $end,
            'total_price'                   => '2500.00',
            'deposit_paid'                  => '0.00',
            'early_termination_rent_policy' => $policy,
        ]);
    }

    private function seedHeldDeposit(int $amountCents = 50000): SecurityDeposit
    {
        $deposit = SecurityDeposit::create([
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => $this->lesseeId,
            'payee_user_id'            => $this->lessorId,
            'amount_cents'             => $amountCents,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
        ]);
        $this->depositIds[] = $deposit->id;

        return $deposit;
    }

    private function seedCollectedPayment(int $grossCents = 100000): LeasePayment
    {
        $payment = LeasePayment::create([
            'lease_id'                 => $this->leaseId,
            'payer_user_id'            => $this->lesseeId,
            'payee_user_id'            => $this->lessorId,
            'stripe_account_id'        => 'acct_' . Str::random(10),
            'gross_cents'              => $grossCents,
            'surcharge_cents'          => 0,
            'application_fee_cents'    => 0,
            'net_cents'                => $grossCents,
            'currency'                 => 'USD',
            'status'                   => 'collected',
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'paid_at'                  => now(),
        ]);
        $this->paymentIds[] = $payment->id;

        return $payment;
    }

    /** Bind a Stripe mock that records every refund amount it is asked for. */
    private function mockStripe(): \stdClass
    {
        $spy = new \stdClass();
        $spy->refunds = [];

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundDestinationCharge')
            ->andReturnUsing(function (string $pi, ?int $amount = null) use ($spy) {
                $spy->refunds[] = $amount;
                return Mockery::mock(Refund::class);
            });
        $this->app->instance(StripeService::class, $stripe);

        return $spy;
    }

    public function test_full_forfeit_files_the_deposit_claim_and_refunds_no_rent(): void
    {
        $this->seedActiveLease('full_forfeit', '2026-10-01', '2026-11-30');
        $deposit = $this->seedHeldDeposit(50000);
        $payment = $this->seedCollectedPayment(100000);

        // A full mock throws on any un-stubbed call — proves no refund happens.
        $this->app->instance(StripeService::class, Mockery::mock(StripeService::class));

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Subletting the stand', null, $this->lessorId);

        $lease = DB::connection('lease')->table('leases')->where('id', $this->leaseId)->first();
        $this->assertSame('terminated', $lease->status);
        $this->assertNotNull($lease->terminated_at);

        $deposit->refresh();
        $this->assertSame('lessee', $deposit->forfeit_fault);          // contestable claim
        $this->assertSame('held', $deposit->status);                   // money still held
        $this->assertSame('pending', $deposit->forfeit_trust_status);
        $this->assertSame(50000, (int) $deposit->forfeited_amount_cents);

        $payment->refresh();
        $this->assertSame('collected', $payment->status);              // rent untouched
    }

    public function test_full_refund_returns_all_prepaid_rent(): void
    {
        $this->seedActiveLease('full_refund', '2026-10-01', '2026-11-30');
        $this->seedHeldDeposit(50000);
        $payment = $this->seedCollectedPayment(100000);
        $spy = $this->mockStripe();

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Repeated trespass', null, $this->lessorId);

        // Full refund → null amount (refund the whole charge), status refunded.
        $this->assertSame([null], $spy->refunds);
        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
    }

    public function test_prorated_refunds_only_the_unused_portion(): void
    {
        // 20-day term, 10 days elapsed → 50% unused → refund half of $1000 gross.
        $this->seedActiveLease('prorated', now()->subDays(10)->toDateString(), now()->addDays(10)->toDateString());
        $this->seedHeldDeposit(50000);
        $payment = $this->seedCollectedPayment(100000);
        $spy = $this->mockStripe();

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Damaged the gate', null, $this->lessorId);

        $this->assertCount(1, $spy->refunds);
        $this->assertSame(50000, $spy->refunds[0]);    // half of $1000
        $payment->refresh();
        $this->assertSame('partially_refunded', $payment->status);
    }

    public function test_disposition_argument_overrides_the_lease_policy(): void
    {
        // Lease defaults to full_forfeit; the landowner overrides to full_refund.
        $this->seedActiveLease('full_forfeit', '2026-10-01', '2026-11-30');
        $this->seedHeldDeposit(50000);
        $payment = $this->seedCollectedPayment(100000);
        $spy = $this->mockStripe();

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Violation', 'full_refund', $this->lessorId);

        $this->assertSame([null], $spy->refunds);
        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
    }

    public function test_a_non_active_lease_cannot_be_terminated_for_violation(): void
    {
        $this->seedActiveLease('full_forfeit', '2026-10-01', '2026-11-30');
        DB::connection('lease')->table('leases')->where('id', $this->leaseId)->update(['status' => 'expired']);

        $this->expectException(\RuntimeException::class);

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Too late', null, $this->lessorId);
    }

    public function test_an_invalid_rent_disposition_is_rejected(): void
    {
        $this->seedActiveLease('full_forfeit', '2026-10-01', '2026-11-30');

        $this->expectException(\InvalidArgumentException::class);

        app(LeaseService::class)->terminateForViolation($this->leaseId, 'Violation', 'bogus_policy', $this->lessorId);
    }
}
