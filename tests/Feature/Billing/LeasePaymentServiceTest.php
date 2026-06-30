<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\LeasePayment;
use App\Models\Billing\StripeAccount;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\Billing\FeeService;
use App\Services\Billing\LeasePaymentService;
use App\Services\Billing\PayoutService;
use App\Services\Billing\StripeService;
use App\Services\Identity\UserService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Stripe\Refund;
use Tests\TestCase;

/**
 * Phase 5.5 — lease-rent collection via Stripe Connect destination charges. Stripe
 * is mocked; lease_payment / booking_deposit rows are real on the `billing`
 * connection (owner role in tests bypasses RLS). createCheckoutSession runs as the
 * runtime member (no local write); recordCollectedFromCheckout is system-authored
 * from a completed Checkout payload and reads the PaymentIntent for the auto-created
 * transfer id (rescued — never fails the webhook).
 */
class LeasePaymentServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $paymentIds = [];
    /** @var array<int,string> */ private array $depositIds = [];

    protected function tearDown(): void
    {
        $billing = DB::connection('billing');
        if ($this->paymentIds) { $billing->table('lease_payments')->whereIn('id', $this->paymentIds)->delete(); }
        if ($this->depositIds) { $billing->table('booking_deposits')->whereIn('id', $this->depositIds)->delete(); }
        parent::tearDown();
    }

    private function service(
        ?StripeService $stripe = null,
        ?PayoutService $payouts = null,
        ?FeeService $fees = null,
        ?PropertyService $properties = null,
    ): LeasePaymentService {
        return new LeasePaymentService(
            $stripe ?? app(StripeService::class),
            $payouts ?? app(PayoutService::class),
            $fees ?? app(FeeService::class),
            $properties ?? app(PropertyService::class),
            app(AuditService::class),
        );
    }

    /** Insert a booking_deposit row credited toward a lease. */
    private function seedBookingDeposit(string $leaseId, int $amountCents, string $status = 'disbursed'): void
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('booking_deposits')->insert([
            'id'                       => $id,
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'amount_cents'             => $amountCents,
            'currency'                 => 'USD',
            'status'                   => $status,
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'collected_at'             => now(),
        ]);
        $this->depositIds[] = $id;
    }

    /** Insert a lease_payment row. */
    private function seedLeasePayment(string $leaseId, int $grossCents, int $surchargeCents, string $status = 'collected'): void
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('lease_payments')->insert([
            'id'                       => $id,
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'stripe_account_id'        => 'acct_' . Str::random(8),
            'gross_cents'              => $grossCents,
            'surcharge_cents'          => $surchargeCents,
            'application_fee_cents'    => 0,
            'net_cents'                => $grossCents - $surchargeCents,
            'currency'                 => 'USD',
            'status'                   => $status,
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'paid_at'                  => now(),
        ]);
        $this->paymentIds[] = $id;
    }

    // ── balanceDueCents ─────────────────────────────────────────────────────────

    public function test_balance_due_subtracts_booking_deposit_and_prior_rent(): void
    {
        $leaseId = (string) Str::uuid();
        $lease   = new Lease(['total_price' => 1000]); // $1000 → 100000c
        $lease->id = $leaseId;                          // id is guarded

        $this->seedBookingDeposit($leaseId, 20000);                 // -$200 deposit
        $this->seedLeasePayment($leaseId, 30500, 500);             // -$300 rent ($5 surcharge excluded)

        // 100000 - 20000 - (30500 - 500) = 50000
        $this->assertSame(50000, $this->service()->balanceDueCents($lease));
    }

    public function test_balance_due_never_goes_negative(): void
    {
        $leaseId = (string) Str::uuid();
        $lease   = new Lease(['total_price' => 100]);
        $lease->id = $leaseId; // id is guarded

        $this->seedLeasePayment($leaseId, 50000, 0); // overpaid relative to total

        $this->assertSame(0, $this->service()->balanceDueCents($lease));
    }

    // ── createCheckoutSession ───────────────────────────────────────────────────

    /** A Lease whose getLessor() resolves to the given landowner via a mocked UserService. */
    private function leaseWithLessor(User $landowner, string $leaseId, float $totalPrice = 1000): Lease
    {
        $users = Mockery::mock(UserService::class);
        $users->shouldReceive('findById')->andReturn($landowner);
        $this->app->instance(UserService::class, $users);

        // Set attributes directly — id/*_user_id are guarded, so the fill() array drops them.
        $lease = new Lease();
        $lease->id             = $leaseId;
        $lease->property_id    = (string) Str::uuid();
        $lease->lessee_user_id = (string) Str::uuid();
        $lease->lessor_user_id = $landowner->id;
        $lease->total_price    = $totalPrice;

        return $lease;
    }

    public function test_create_checkout_session_throws_when_landowner_cannot_take_charges(): void
    {
        $landowner = new User(); $landowner->id = (string) Str::uuid(); // id is guarded
        $lease     = $this->leaseWithLessor($landowner, (string) Str::uuid());

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('connectAccount')->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->service(payouts: $payouts)
            ->createCheckoutSession($lease, $landowner, 'https://app.test/ok', 'https://app.test/cancel');
    }

    public function test_create_checkout_session_builds_destination_charge_with_application_fee(): void
    {
        $leaseId   = (string) Str::uuid();
        $landowner = new User(); $landowner->id = (string) Str::uuid(); // id is guarded
        $lease     = $this->leaseWithLessor($landowner, $leaseId, 1000); // 100000c balance, no prior payments

        $account = new StripeAccount(['stripe_account_id' => 'acct_dst_1', 'charges_enabled' => true]);

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('connectAccount')->andReturn($account);
        $payouts->shouldReceive('quote')->with($landowner, 100000)
            ->andReturn(['gross_cents' => 100000, 'fee_pct' => 5.0, 'fee_cents' => 5000, 'net_cents' => 95000]);

        $fees = Mockery::mock(FeeService::class);
        $fees->shouldReceive('processingFee')->andReturn(['fee_cents' => 300]); // $3 surcharge

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('createConnectCheckoutSession')
            ->once()
            ->with(
                $landowner,
                100300,            // gross = balance + surcharge
                5300,              // application fee = tier fee + surcharge
                'acct_dst_1',      // destination
                Mockery::on(fn ($meta) => $meta['purpose'] === 'lease_payment'
                    && $meta['gross_cents'] === '100300'
                    && $meta['surcharge_cents'] === '300'
                    && $meta['application_fee_cents'] === '5300'
                    && $meta['net_cents'] === '95000'
                    && $meta['stripe_account_id'] === 'acct_dst_1'),
                'https://app.test/ok',
                'https://app.test/cancel',
                Mockery::type('string'),
            )
            ->andReturn(Session::constructFrom(['id' => 'cs_lease_1']));

        $session = $this->service(stripe: $stripe, payouts: $payouts, fees: $fees)
            ->createCheckoutSession($lease, $landowner, 'https://app.test/ok', 'https://app.test/cancel');

        $this->assertSame('cs_lease_1', $session->id);
    }

    // ── recordCollectedFromCheckout ─────────────────────────────────────────────

    private function checkoutPayload(string $leaseId, string $pi): array
    {
        return [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'payment_status' => 'paid',
            'currency'       => 'usd',
            'metadata'       => [
                'purpose'               => 'lease_payment',
                'lease_id'              => $leaseId,
                'payer_user_id'         => (string) Str::uuid(),
                'payee_user_id'         => (string) Str::uuid(),
                'stripe_account_id'     => 'acct_rec_1',
                'gross_cents'           => '100300',
                'surcharge_cents'       => '300',
                'application_fee_cents' => '5300',
                'net_cents'             => '95000',
            ],
        ];
    }

    private function stripeReturningTransfer(string $transferId = 'tr_lease_1'): StripeService
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('chargeAndTransferForPaymentIntent')
            ->andReturn(['charge_id' => 'ch_lease_1', 'transfer_id' => $transferId]);

        return $stripe;
    }

    public function test_record_collected_stores_money_breakdown_and_transfer_id(): void
    {
        $pi      = 'pi_' . Str::random(14);
        $payment = $this->service(stripe: $this->stripeReturningTransfer('tr_rec_9'))
            ->recordCollectedFromCheckout($this->checkoutPayload((string) Str::uuid(), $pi));

        $this->assertNotNull($payment);
        $this->paymentIds[] = $payment->id;

        $this->assertSame('collected', $payment->status);
        $this->assertSame(100300, (int) $payment->gross_cents);
        $this->assertSame(300, (int) $payment->surcharge_cents);
        $this->assertSame(5300, (int) $payment->application_fee_cents);
        $this->assertSame(95000, (int) $payment->net_cents);
        $this->assertSame('USD', $payment->currency);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($pi, $payment->stripe_payment_intent_id);
        $this->assertSame('tr_rec_9', $payment->getAttribute('stripe_transfer_id'));
        $this->assertSame('ch_lease_1', $payment->getAttribute('stripe_charge_id'));
    }

    public function test_record_collected_is_idempotent_on_payment_intent(): void
    {
        $service = $this->service(stripe: $this->stripeReturningTransfer());
        $pi      = 'pi_' . Str::random(14);
        $payload = $this->checkoutPayload((string) Str::uuid(), $pi);

        $first  = $service->recordCollectedFromCheckout($payload);
        $second = $service->recordCollectedFromCheckout($payload);
        $this->paymentIds[] = $first->id;

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LeasePayment::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_record_collected_ignores_non_lease_sessions(): void
    {
        $this->assertNull($this->service()->recordCollectedFromCheckout([
            'mode'     => 'payment',
            'metadata' => ['purpose' => 'booking_deposit'],
        ]));
    }

    /**
     * SEC-058: an unpaid (abandoned) Checkout session — replayable through the
     * user-supplied session_id on the db.system success-return — must NOT author a
     * collected lease payment (and so must not reduce the balance or activate the
     * lease). In payment mode the PaymentIntent id is present before payment, so only
     * payment_status gates this.
     */
    public function test_record_collected_rejects_an_unpaid_session(): void
    {
        $pi      = 'pi_' . Str::random(14);
        $payload = $this->checkoutPayload((string) Str::uuid(), $pi);
        $payload['payment_status'] = 'unpaid';

        $this->assertNull($this->service()->recordCollectedFromCheckout($payload));
        $this->assertSame(0, LeasePayment::where('stripe_payment_intent_id', $pi)->count());
    }

    // ── refund ──────────────────────────────────────────────────────────────────

    public function test_refund_full_marks_refunded(): void
    {
        $leaseId = (string) Str::uuid();
        $this->seedLeasePayment($leaseId, 100300, 300);
        $payment = LeasePayment::where('lease_id', $leaseId)->first();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundDestinationCharge')
            ->once()->with($payment->stripe_payment_intent_id, null)
            ->andReturn(Refund::constructFrom(['id' => 're_1']));

        $result = $this->service(stripe: $stripe)->refund($payment, null, (string) Str::uuid());

        $this->assertSame('refunded', $result->status);
        $this->assertSame('refunded', LeasePayment::find($payment->id)->status);
    }

    public function test_refund_partial_marks_partially_refunded(): void
    {
        $leaseId = (string) Str::uuid();
        $this->seedLeasePayment($leaseId, 100300, 300);
        $payment = LeasePayment::where('lease_id', $leaseId)->first();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundDestinationCharge')
            ->once()->with($payment->stripe_payment_intent_id, 50000)
            ->andReturn(Refund::constructFrom(['id' => 're_2']));

        $result = $this->service(stripe: $stripe)->refund($payment, 50000, (string) Str::uuid());

        $this->assertSame('partially_refunded', $result->status);
    }
}
