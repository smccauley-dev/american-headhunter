<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\BookingDeposit;
use App\Models\Billing\StripeAccount;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Models\Property\PropertyListing;
use App\Services\Audit\AuditService;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\PayoutService;
use App\Services\Billing\StripeService;
use App\Services\Lease\ApplicationService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Vet-first booking fee, HELD on the platform (a plain charge — not a destination
 * charge). The applicant pays after approval to claim the spot; the webhook authors
 * the held row and drives the win/lose outcome:
 *  - won  → status 'held', lease_id backfilled, credited toward the lease total
 *  - lost → the held fee is refunded and the row flipped to 'refunded'
 *  - completion / forfeiture → routed to the landowner ('disbursed' / 'forfeited')
 *
 * Stripe and (for the webhook-authored path) ApplicationService are mocked; the
 * deposit rows are real on the `billing` connection (owner role in tests bypasses RLS).
 */
class BookingDepositServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $paymentIntentIds = [];
    /** @var array<int,string> */ private array $depositIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    protected function tearDown(): void
    {
        $billing = DB::connection('billing');
        if ($this->paymentIntentIds) {
            $billing->table('booking_deposits')->whereIn('stripe_payment_intent_id', $this->paymentIntentIds)->delete();
        }
        if ($this->depositIds) {
            $billing->table('booking_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        if ($this->userIds) {
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    private function seedLandowner(): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "booking-payee-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'landowner',
        ]);
        $this->userIds[] = $id;

        return $id;
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

    private function listingService(PropertyListing $listing): PropertyService
    {
        $props = Mockery::mock(PropertyService::class);
        $props->shouldReceive('findListing')->andReturn($listing);

        return $props;
    }

    /** An in-memory approved application with an open booking-fee window. */
    private function approvedApplication(): LeaseApplication
    {
        $app = new LeaseApplication();
        $app->id                      = (string) Str::uuid();
        $app->listing_id              = (string) Str::uuid();
        $app->status                  = 'approved';
        $app->booking_fee_deadline    = now()->addDay();
        $app->property_id_snapshot    = (string) Str::uuid();
        $app->property_title_snapshot = 'Booking Fee Test Ranch';

        return $app;
    }

    // ── amountDueForApplication ───────────────────────────────────────────────────

    public function test_amount_due_uses_flat_listing_booking_deposit(): void
    {
        $service = $this->service(properties: $this->listingService(new PropertyListing(['booking_deposit_amount' => 125.00])));

        $this->assertSame(12500, $service->amountDueForApplication($this->approvedApplication()));
    }

    public function test_amount_due_uses_percent_of_listing_total(): void
    {
        $listing = new PropertyListing(['booking_deposit_percent' => 20, 'price_total' => 500]);
        $service = $this->service(properties: $this->listingService($listing));

        // 20% of $500 = $100
        $this->assertSame(10000, $service->amountDueForApplication($this->approvedApplication()));
    }

    public function test_amount_due_is_zero_when_no_booking_deposit_configured(): void
    {
        $service = $this->service(properties: $this->listingService(new PropertyListing([])));

        $this->assertSame(0, $service->amountDueForApplication($this->approvedApplication()));
    }

    // ── createCheckoutSession (held charge) ───────────────────────────────────────

    public function test_create_checkout_session_throws_when_the_window_is_closed(): void
    {
        $app = $this->approvedApplication();
        $app->status = 'closed';

        $payer = new User(); $payer->id = (string) Str::uuid();

        $this->expectException(\RuntimeException::class);
        $this->service()->createCheckoutSession($app, $payer, 'https://app.test/ok', 'https://app.test/cancel');
    }

    public function test_create_checkout_session_throws_when_no_fee_is_due(): void
    {
        $app   = $this->approvedApplication();
        $payer = new User(); $payer->id = (string) Str::uuid();

        $service = $this->service(properties: $this->listingService(new PropertyListing([])));

        $this->expectException(\RuntimeException::class);
        $service->createCheckoutSession($app, $payer, 'https://app.test/ok', 'https://app.test/cancel');
    }

    public function test_create_checkout_session_builds_a_held_charge(): void
    {
        $app   = $this->approvedApplication();
        $payer = new User(); $payer->id = (string) Str::uuid();

        // Held — a plain charge on the platform, not a destination charge.
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('createDepositCheckoutSession')
            ->once()
            ->with(
                $payer,
                12500,
                Mockery::on(fn ($meta) => $meta['purpose'] === 'booking_fee'
                    && $meta['application_id'] === $app->id
                    && $meta['listing_id'] === $app->listing_id
                    && $meta['amount_cents'] === '12500'),
                'https://app.test/ok',
                'https://app.test/cancel',
                Mockery::type('string'),
            )
            ->andReturn(Session::constructFrom(['id' => 'cs_fee_1', 'url' => 'https://stripe.test/cs']));

        $service = $this->service(
            stripe: $stripe,
            properties: $this->listingService(new PropertyListing(['booking_deposit_amount' => 125.00])),
        );

        $session = $service->createCheckoutSession($app, $payer, 'https://app.test/ok', 'https://app.test/cancel');

        $this->assertSame('cs_fee_1', $session->id);
    }

    // ── recordPaidFromCheckout: win / lose / idempotency ──────────────────────────

    private function checkoutPayload(string $applicationId, string $pi, int $amount = 12500): array
    {
        return [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'currency'       => 'usd',
            'amount_total'   => $amount,
            'metadata'       => [
                'purpose'        => 'booking_fee',
                'application_id' => $applicationId,
                'listing_id'     => (string) Str::uuid(),
                'payer_user_id'  => (string) Str::uuid(),
                'payee_user_id'  => (string) Str::uuid(),
                'amount_cents'   => (string) $amount,
            ],
        ];
    }

    /** A StripeService whose held-charge id lookup returns a known charge id. */
    private function stripeWithChargeId(string $chargeId = 'ch_fee_1'): StripeService
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('chargeIdForPaymentIntent')->andReturn($chargeId);

        return $stripe;
    }

    /** Stub the win/lose orchestration that recordPaidFromCheckout delegates to. */
    private function stubOnBookingFeePaid(array $result): void
    {
        $applications = Mockery::mock(ApplicationService::class);
        $applications->shouldReceive('onBookingFeePaid')->andReturn($result);
        $this->app->instance(ApplicationService::class, $applications);
    }

    public function test_record_paid_holds_the_fee_and_attaches_the_won_lease(): void
    {
        $applicationId = (string) Str::uuid();
        $pi            = 'pi_' . Str::random(14);
        $this->paymentIntentIds[] = $pi;

        $lease = new Lease(); $lease->id = (string) Str::uuid();
        $this->stubOnBookingFeePaid(['outcome' => 'won', 'lease' => $lease]);

        $deposit = $this->service(stripe: $this->stripeWithChargeId('ch_fee_won'))
            ->recordPaidFromCheckout($this->checkoutPayload($applicationId, $pi));

        $this->assertNotNull($deposit);
        $this->assertSame('held', $deposit->status);
        $this->assertSame(12500, (int) $deposit->amount_cents);
        $this->assertSame($lease->id, $deposit->lease_id);
        $this->assertNotNull($deposit->collected_at);
        $this->assertSame($pi, $deposit->getAttribute('stripe_payment_intent_id'));
    }

    public function test_record_paid_refunds_when_the_payer_loses_the_race(): void
    {
        $applicationId = (string) Str::uuid();
        $pi            = 'pi_' . Str::random(14);
        $this->paymentIntentIds[] = $pi;

        $this->stubOnBookingFeePaid(['outcome' => 'lost', 'lease' => null]);

        $stripe = $this->stripeWithChargeId('ch_fee_lost');
        $stripe->shouldReceive('refundPaymentIntent')->once()->andReturnNull();

        $deposit = $this->service(stripe: $stripe)->recordPaidFromCheckout($this->checkoutPayload($applicationId, $pi));

        $this->assertSame('refunded', $deposit->status);
        $this->assertNull($deposit->lease_id);
        $this->assertNotNull($deposit->refunded_at);
    }

    public function test_record_paid_is_idempotent_on_payment_intent(): void
    {
        $applicationId = (string) Str::uuid();
        $pi            = 'pi_' . Str::random(14);
        $this->paymentIntentIds[] = $pi;

        $lease = new Lease(); $lease->id = (string) Str::uuid();
        $this->stubOnBookingFeePaid(['outcome' => 'won', 'lease' => $lease]);

        $service = $this->service(stripe: $this->stripeWithChargeId());
        $payload = $this->checkoutPayload($applicationId, $pi);

        $first  = $service->recordPaidFromCheckout($payload);
        $second = $service->recordPaidFromCheckout($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BookingDeposit::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_record_paid_ignores_non_booking_fee_sessions(): void
    {
        $this->assertNull($this->service()->recordPaidFromCheckout([
            'mode'     => 'payment',
            'metadata' => ['purpose' => 'security_deposit'],
        ]));
    }

    // ── disburseForLease / forfeitForLease (route to landowner) ───────────────────

    private function seedHeldDeposit(string $leaseId, string $payeeUserId): BookingDeposit
    {
        $deposit = BookingDeposit::create([
            'application_id'           => (string) Str::uuid(),
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => $payeeUserId,
            'amount_cents'             => 12500,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => 'pi_' . Str::random(14),
            'stripe_charge_id'         => 'ch_' . Str::random(14),
            'collected_at'             => now(),
        ]);
        $this->depositIds[] = $deposit->id;

        return $deposit;
    }

    /**
     * PayoutService + StripeService that transfer the held net to the landowner.
     * routeToLandowner resolves the payee via User::on('identity')->find(), so the
     * payee must be a real identity row.
     */
    private function payingServices(int $feeCents, int $netCents): array
    {
        $account = new StripeAccount(['stripe_account_id' => 'acct_pay_1', 'charges_enabled' => true]);

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('connectAccount')->andReturn($account);
        $payouts->shouldReceive('quote')->andReturn(['fee_cents' => $feeCents, 'net_cents' => $netCents]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('createTransfer')->once()
            ->with($netCents, 'acct_pay_1', Mockery::type('array'))
            ->andReturn(\Stripe\Transfer::constructFrom(['id' => 'tr_pay_1']));

        return [$stripe, $payouts];
    }

    public function test_disburse_for_lease_routes_the_net_to_the_landowner(): void
    {
        $payeeUserId = $this->seedLandowner();
        $leaseId     = (string) Str::uuid();
        $deposit     = $this->seedHeldDeposit($leaseId, $payeeUserId);

        [$stripe, $payouts] = $this->payingServices(625, 11875);

        $this->service(stripe: $stripe, payouts: $payouts)->disburseForLease($leaseId);

        $deposit->refresh();
        $this->assertSame('disbursed', $deposit->status);
        $this->assertSame(11875, (int) $deposit->net_cents);
        $this->assertSame(625, (int) $deposit->application_fee_cents);
        $this->assertNotNull($deposit->disbursed_at);
        $this->assertSame('tr_pay_1', $deposit->getAttribute('stripe_transfer_id'));
    }

    public function test_forfeit_for_lease_routes_the_net_to_the_landowner(): void
    {
        $payeeUserId = $this->seedLandowner();
        $leaseId     = (string) Str::uuid();
        $deposit     = $this->seedHeldDeposit($leaseId, $payeeUserId);

        [$stripe, $payouts] = $this->payingServices(625, 11875);

        $this->service(stripe: $stripe, payouts: $payouts)->forfeitForLease($leaseId);

        $deposit->refresh();
        $this->assertSame('forfeited', $deposit->status);
        $this->assertNotNull($deposit->forfeited_at);
        $this->assertSame('tr_pay_1', $deposit->getAttribute('stripe_transfer_id'));
    }

    public function test_disburse_is_a_noop_without_a_held_fee(): void
    {
        // No held row for this lease → nothing to route, no Stripe call.
        $this->service()->disburseForLease((string) Str::uuid());
        $this->assertTrue(true);
    }
}
