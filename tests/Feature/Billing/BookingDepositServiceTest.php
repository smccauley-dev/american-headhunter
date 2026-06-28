<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\BookingDeposit;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
use App\Services\Audit\AuditService;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\PayoutService;
use App\Services\Billing\StripeService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Phase 5.x — non-refundable booking deposit, collected as a Stripe Connect
 * destination charge: the customer pays the deposit, the net is transferred to the
 * landowner at charge time, and the row is recorded 'disbursed'. Stripe is mocked;
 * the deposit rows are real on the `billing` connection (owner role in tests
 * bypasses RLS). recordCollectedFromCheckout reads the PaymentIntent to capture the
 * auto-created transfer id (rescued — never fails the webhook).
 */
class BookingDepositServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $depositIds = [];

    protected function tearDown(): void
    {
        if ($this->depositIds) {
            DB::connection('billing')->table('booking_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        parent::tearDown();
    }

    private function service(
        ?StripeService $stripe = null,
        ?PropertyService $properties = null,
        ?PayoutService $payouts = null,
    ): BookingDepositService {
        return new BookingDepositService(
            $stripe ?? app(StripeService::class),
            $payouts ?? app(PayoutService::class),
            $properties ?? app(PropertyService::class),
            app(AuditService::class),
        );
    }

    /** A StripeService whose webhook-side PI read returns a known transfer id. */
    private function stripeReturningTransfer(string $transferId = 'tr_book_123'): StripeService
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('chargeAndTransferForPaymentIntent')
            ->andReturn(['charge_id' => 'ch_book_123', 'transfer_id' => $transferId]);

        return $stripe;
    }

    private function listingService(PropertyListing $listing): PropertyService
    {
        $props = Mockery::mock(PropertyService::class);
        $props->shouldReceive('findListing')->andReturn($listing);

        return $props;
    }

    // ── amountDueCents ──────────────────────────────────────────────────────────

    public function test_amount_due_uses_flat_listing_booking_deposit(): void
    {
        $lease   = new Lease(['listing_id' => (string) Str::uuid(), 'total_price' => 500]);
        $service = $this->service(properties: $this->listingService(new PropertyListing(['booking_deposit_amount' => 125.00])));

        $this->assertSame(12500, $service->amountDueCents($lease));
    }

    public function test_amount_due_uses_percent_of_total(): void
    {
        $lease   = new Lease(['listing_id' => (string) Str::uuid(), 'total_price' => 500]);
        $service = $this->service(properties: $this->listingService(new PropertyListing(['booking_deposit_percent' => 20])));

        // 20% of $500 = $100
        $this->assertSame(10000, $service->amountDueCents($lease));
    }

    public function test_amount_due_is_zero_when_no_booking_deposit_configured(): void
    {
        $lease   = new Lease(['listing_id' => (string) Str::uuid(), 'total_price' => 500]);
        $service = $this->service(properties: $this->listingService(new PropertyListing([])));

        $this->assertSame(0, $service->amountDueCents($lease));
    }

    public function test_amount_due_is_zero_without_a_listing(): void
    {
        $lease = new Lease(['total_price' => 500]);

        $this->assertSame(0, $this->service()->amountDueCents($lease));
    }

    // ── recordCollectedFromCheckout ─────────────────────────────────────────────

    private function checkoutPayload(string $leaseId, string $pi, int $amount = 12500): array
    {
        return [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'currency'       => 'usd',
            'amount_total'   => $amount,
            'metadata'       => [
                'purpose'               => 'booking_deposit',
                'lease_id'              => $leaseId,
                'payer_user_id'         => (string) Str::uuid(),
                'payee_user_id'         => (string) Str::uuid(),
                'stripe_account_id'     => 'acct_book_' . Str::random(8),
                'amount_cents'          => (string) $amount,
                'application_fee_cents' => (string) ((int) round($amount * 0.05)),
                'net_cents'             => (string) ($amount - (int) round($amount * 0.05)),
            ],
        ];
    }

    public function test_record_collected_creates_a_disbursed_deposit(): void
    {
        $pi      = 'pi_' . Str::random(14);
        $deposit = $this->service(stripe: $this->stripeReturningTransfer('tr_book_abc'))
            ->recordCollectedFromCheckout($this->checkoutPayload((string) Str::uuid(), $pi));

        $this->assertNotNull($deposit);
        $this->depositIds[] = $deposit->id;

        // Destination charge: the net is transferred to the landowner at charge time,
        // so the deposit is recorded 'disbursed' with the auto-created transfer id.
        $this->assertSame('disbursed', $deposit->status);
        $this->assertSame(12500, (int) $deposit->amount_cents);
        $this->assertSame(625, (int) $deposit->application_fee_cents);
        $this->assertSame(11875, (int) $deposit->net_cents);
        $this->assertSame('USD', $deposit->currency);
        $this->assertNotNull($deposit->collected_at);
        $this->assertNotNull($deposit->disbursed_at);
        $this->assertSame($pi, $deposit->stripe_payment_intent_id);
        $this->assertSame('tr_book_abc', $deposit->getAttribute('stripe_transfer_id'));
    }

    public function test_record_collected_is_idempotent_on_payment_intent(): void
    {
        $service = $this->service(stripe: $this->stripeReturningTransfer());
        $pi      = 'pi_' . Str::random(14);
        $payload = $this->checkoutPayload((string) Str::uuid(), $pi);

        $first  = $service->recordCollectedFromCheckout($payload);
        $second = $service->recordCollectedFromCheckout($payload);
        $this->depositIds[] = $first->id;

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BookingDeposit::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_record_collected_ignores_non_booking_sessions(): void
    {
        $this->assertNull($this->service()->recordCollectedFromCheckout([
            'mode'     => 'payment',
            'metadata' => ['purpose' => 'security_deposit'],
        ]));
    }

    // ── createCheckoutSession (destination charge) ──────────────────────────────

    /** A Lease whose getLessor() resolves to the given landowner via a mocked UserService. */
    private function leaseWithLessor(\App\Models\Identity\User $landowner, int $depositCents): Lease
    {
        $users = Mockery::mock(\App\Services\Identity\UserService::class);
        $users->shouldReceive('findById')->andReturn($landowner);
        $this->app->instance(\App\Services\Identity\UserService::class, $users);

        // Set attributes directly — id/*_user_id are guarded, so the fill() array drops them.
        $lease = new Lease();
        $lease->id             = (string) Str::uuid();
        $lease->listing_id     = (string) Str::uuid();
        $lease->property_id    = (string) Str::uuid();
        $lease->lessee_user_id = (string) Str::uuid();
        $lease->lessor_user_id = $landowner->id;
        $lease->total_price    = 1000;

        return $lease;
    }

    public function test_create_checkout_session_throws_when_landowner_cannot_take_charges(): void
    {
        $landowner = new \App\Models\Identity\User(); $landowner->id = (string) Str::uuid(); // id is guarded
        $lease     = $this->leaseWithLessor($landowner, 12500);

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('connectAccount')->andReturn(null); // no Connect account at all

        $service = $this->service(
            properties: $this->listingService(new PropertyListing(['booking_deposit_amount' => 125.00])),
            payouts: $payouts,
        );

        $this->expectException(\RuntimeException::class);
        $service->createCheckoutSession($lease, $landowner, 'https://app.test/ok', 'https://app.test/cancel');
    }

    public function test_create_checkout_session_builds_destination_charge(): void
    {
        $landowner = new \App\Models\Identity\User(); $landowner->id = (string) Str::uuid(); // id is guarded
        $lease     = $this->leaseWithLessor($landowner, 12500);

        $account = new \App\Models\Billing\StripeAccount([
            'stripe_account_id' => 'acct_dst_999',
            'charges_enabled'   => true,
        ]);

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('connectAccount')->andReturn($account);
        $payouts->shouldReceive('quote')->with($landowner, 12500)
            ->andReturn(['gross_cents' => 12500, 'fee_pct' => 5.0, 'fee_cents' => 625, 'net_cents' => 11875]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('createConnectCheckoutSession')
            ->once()
            ->with(
                $landowner,
                12500,                 // gross (deposit, no surcharge)
                625,                   // application fee = tier fee
                'acct_dst_999',        // destination
                Mockery::on(fn ($meta) => $meta['purpose'] === 'booking_deposit'
                    && $meta['stripe_account_id'] === 'acct_dst_999'
                    && $meta['application_fee_cents'] === '625'
                    && $meta['net_cents'] === '11875'),
                'https://app.test/ok',
                'https://app.test/cancel',
                Mockery::type('string'),
            )
            ->andReturn(Session::constructFrom(['id' => 'cs_book_1', 'url' => 'https://stripe.test/cs']));

        $service = $this->service(
            stripe: $stripe,
            properties: $this->listingService(new PropertyListing(['booking_deposit_amount' => 125.00])),
            payouts: $payouts,
        );

        $session = $service->createCheckoutSession($lease, $landowner, 'https://app.test/ok', 'https://app.test/cancel');

        $this->assertSame('cs_book_1', $session->id);
    }
}
