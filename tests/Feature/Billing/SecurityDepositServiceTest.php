<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\SecurityDeposit;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
use App\Services\Audit\AuditService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Billing\StripeService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

/**
 * Phase 5 — security deposit lifecycle (capture/release/forfeit). Stripe is
 * mocked; the deposit rows are real on the `billing` connection (owner role in
 * tests bypasses RLS). recordHeldFromCheckout makes NO Stripe call — it only
 * authors the row from a completed Checkout payload.
 */
class SecurityDepositServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $depositIds = [];

    protected function tearDown(): void
    {
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        parent::tearDown();
    }

    private function service(?StripeService $stripe = null, ?PropertyService $properties = null): SecurityDepositService
    {
        return new SecurityDepositService(
            $stripe ?? app(StripeService::class),
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

    private function seedHeld(int $amountCents = 5000, string $pi = 'pi_seed'): SecurityDeposit
    {
        $deposit = SecurityDeposit::create([
            'lease_id'                 => (string) Str::uuid(),
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'amount_cents'             => $amountCents,
            'currency'                 => 'USD',
            'status'                   => 'held',
            'stripe_payment_intent_id' => $pi,
            'held_at'                  => now(),
        ]);
        $this->depositIds[] = $deposit->id;

        return $deposit;
    }

    // ── amountDueCents ──────────────────────────────────────────────────────────

    public function test_amount_due_uses_flat_listing_deposit(): void
    {
        $lease   = new Lease(['listing_id' => (string) Str::uuid(), 'total_price' => 500]);
        $service = $this->service(properties: $this->listingService(new PropertyListing(['deposit_amount' => 75.00])));

        $this->assertSame(7500, $service->amountDueCents($lease));
    }

    public function test_amount_due_uses_percent_of_total(): void
    {
        $lease   = new Lease(['listing_id' => (string) Str::uuid(), 'total_price' => 500]);
        $service = $this->service(properties: $this->listingService(new PropertyListing(['deposit_percent' => 10])));

        // 10% of $500 = $50
        $this->assertSame(5000, $service->amountDueCents($lease));
    }

    public function test_amount_due_is_zero_when_no_deposit_configured(): void
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

    // ── recordHeldFromCheckout ──────────────────────────────────────────────────

    private function checkoutPayload(string $leaseId, string $pi, int $amount = 7500): array
    {
        return [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'currency'       => 'usd',
            'amount_total'   => $amount,
            'metadata'       => [
                'purpose'       => 'security_deposit',
                'lease_id'      => $leaseId,
                'payer_user_id' => (string) Str::uuid(),
                'payee_user_id' => (string) Str::uuid(),
                'amount_cents'  => (string) $amount,
            ],
        ];
    }

    public function test_record_held_creates_a_held_deposit(): void
    {
        $pi      = 'pi_' . Str::random(14);
        $deposit = $this->service()->recordHeldFromCheckout($this->checkoutPayload((string) Str::uuid(), $pi));

        $this->assertNotNull($deposit);
        $this->depositIds[] = $deposit->id;

        $this->assertSame('held', $deposit->status);
        $this->assertSame(7500, (int) $deposit->amount_cents);
        $this->assertSame('USD', $deposit->currency);
        $this->assertNotNull($deposit->held_at);
        $this->assertSame($pi, $deposit->stripe_payment_intent_id);
    }

    public function test_record_held_is_idempotent_on_payment_intent(): void
    {
        $service = $this->service();
        $pi      = 'pi_' . Str::random(14);
        $payload = $this->checkoutPayload((string) Str::uuid(), $pi);

        $first  = $service->recordHeldFromCheckout($payload);
        $second = $service->recordHeldFromCheckout($payload);
        $this->depositIds[] = $first->id;

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SecurityDeposit::where('stripe_payment_intent_id', $pi)->count());
    }

    public function test_record_held_ignores_non_deposit_sessions(): void
    {
        $this->assertNull($this->service()->recordHeldFromCheckout([
            'mode'     => 'payment',
            'metadata' => ['purpose' => 'something_else'],
        ]));
    }

    // ── release ─────────────────────────────────────────────────────────────────

    public function test_release_refunds_the_remaining_balance(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_rel');

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_rel', 5000, null)
            ->andReturn(Refund::constructFrom(['id' => 're_rel']));

        $result = $this->service(stripe: $stripe)->release($deposit->id, (string) Str::uuid());

        $this->assertSame('released', $result->status);
        $this->assertSame(5000, (int) $result->refunded_amount_cents);
        $this->assertSame('re_rel', $result->stripe_refund_id);
        $this->assertNotNull($result->released_at);
    }

    public function test_release_rejects_a_non_held_deposit(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_x');
        $deposit->status = 'released';
        $deposit->save();

        $this->expectException(\RuntimeException::class);
        $this->service()->release($deposit->id);
    }

    // ── forfeit ─────────────────────────────────────────────────────────────────

    public function test_full_forfeit_does_not_refund(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_ff');

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent');

        $result = $this->service(stripe: $stripe)->forfeit($deposit->id, 5000, 'Property damage', (string) Str::uuid());

        $this->assertSame('forfeited', $result->status);
        $this->assertSame(5000, (int) $result->forfeited_amount_cents);
        $this->assertSame(0, (int) $result->refunded_amount_cents);
        $this->assertSame('Property damage', $result->forfeit_reason);
    }

    public function test_partial_forfeit_returns_the_remainder(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_pf');

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('refundPaymentIntent')
            ->once()
            ->with('pi_pf', 3000, 'Security deposit partial return')
            ->andReturn(Refund::constructFrom(['id' => 're_pf']));

        $result = $this->service(stripe: $stripe)->forfeit($deposit->id, 2000, 'Minor repairs', (string) Str::uuid());

        $this->assertSame('partially_released', $result->status);
        $this->assertSame(2000, (int) $result->forfeited_amount_cents);
        $this->assertSame(3000, (int) $result->refunded_amount_cents);
    }

    public function test_forfeit_rejects_an_amount_over_the_balance(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_over');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->forfeit($deposit->id, 6000, 'Too much');
    }
}
