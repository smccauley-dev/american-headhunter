<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Payout;
use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
use App\Services\Audit\AuditService;
use App\Services\Billing\PayoutService;
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
    /** @var array<int,string> */ private array $userIds = [];

    protected function tearDown(): void
    {
        if ($this->depositIds) {
            DB::connection('billing')->table('security_deposits')->whereIn('id', $this->depositIds)->delete();
        }
        if ($this->userIds) {
            DB::connection('identity')->table('trust_score_events')->whereIn('user_id', $this->userIds)->delete();
            DB::connection('identity')->table('users')->whereIn('id', $this->userIds)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(string $accountType, int $trustScore = 50): string
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "deposit-{$accountType}-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => $accountType,
            'trust_score'   => $trustScore,
        ]);
        $this->userIds[] = $id;

        return $id;
    }

    private function makeLandowner(): string
    {
        return $this->makeUser('landowner');
    }

    /** A held deposit whose parties are the given users (defaults to random uuids). */
    private function seedHeldFor(?string $payerId = null, ?string $payeeId = null, int $amountCents = 5000): SecurityDeposit
    {
        $deposit = $this->seedHeld($amountCents, 'pi_' . Str::random(12));
        $deposit->payer_user_id = $payerId ?? $deposit->payer_user_id;
        $deposit->payee_user_id = $payeeId ?? $deposit->payee_user_id;
        $deposit->save();

        return $deposit;
    }

    /** Insert a concluded deposit row directly (for report aggregation tests). */
    private function seedResolved(string $payeeId, string $status, int $forfeitedCents): void
    {
        $deposit = SecurityDeposit::create([
            'lease_id'               => (string) Str::uuid(),
            'payer_user_id'          => (string) Str::uuid(),
            'payee_user_id'          => $payeeId,
            'amount_cents'           => 5000,
            'forfeited_amount_cents' => $forfeitedCents,
            'currency'               => 'USD',
            'status'                 => $status,
        ]);
        $this->depositIds[] = $deposit->id;
    }

    private function service(?StripeService $stripe = null, ?PropertyService $properties = null, ?PayoutService $payouts = null): SecurityDepositService
    {
        return new SecurityDepositService(
            $stripe ?? app(StripeService::class),
            $properties ?? app(PropertyService::class),
            app(AuditService::class),
            $payouts ?? app(PayoutService::class),
            app(\App\Services\Identity\TrustScoreService::class),
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

    // ── forfeit → Connect disbursement (slice 3) ─────────────────────────────────

    public function test_forfeit_disburses_to_an_onboarded_landowner(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_pay');
        $deposit->payee_user_id = $this->makeLandowner();
        $deposit->save();

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('refundPaymentIntent');

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('canReceivePayouts')->once()->andReturnTrue();
        $payouts->shouldReceive('disburse')
            ->once()
            ->with(Mockery::type(User::class), 5000, Mockery::type('array'))
            ->andReturn((new Payout())->forceFill(['id' => 'po_forfeit']));

        $result = $this->service(stripe: $stripe, payouts: $payouts)
            ->forfeit($deposit->id, 5000, 'Property damage', (string) Str::uuid());

        $this->assertSame('forfeited', $result->status);
        $this->assertSame(5000, (int) $result->forfeited_amount_cents);
    }

    public function test_forfeit_defers_payout_when_landowner_not_onboarded(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_defer');
        $deposit->payee_user_id = $this->makeLandowner();
        $deposit->save();

        $payouts = Mockery::mock(PayoutService::class);
        $payouts->shouldReceive('canReceivePayouts')->once()->andReturnFalse();
        $payouts->shouldNotReceive('disburse');

        $result = $this->service(payouts: $payouts)
            ->forfeit($deposit->id, 5000, 'Property damage', (string) Str::uuid());

        // The forfeiture is still recorded — the cash just stays captured.
        $this->assertSame('forfeited', $result->status);
        $this->assertSame(5000, (int) $result->forfeited_amount_cents);
    }

    // ── forfeit attribution + provisional Trust Score ────────────────────────────

    public function test_hunter_fault_forfeit_parks_a_pending_trust_decision(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_fault');

        $result = $this->service()->forfeit(
            $deposit->id, 5000, 'Cabin damage', (string) Str::uuid(),
            SecurityDepositService::FAULT_LESSEE, 'property_damage',
        );

        $this->assertSame('lessee', $result->forfeit_fault);
        $this->assertSame('property_damage', $result->forfeit_category);
        $this->assertSame('pending', $result->forfeit_trust_status);
    }

    public function test_landowner_initiated_forfeit_parks_no_trust_penalty(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_noinit');

        $result = $this->service()->forfeit(
            $deposit->id, 5000, 'Owner cancelled, kept cleaning fee', (string) Str::uuid(),
            SecurityDepositService::FAULT_LANDOWNER_INITIATED, 'cleaning',
        );

        $this->assertSame('landowner_initiated', $result->forfeit_fault);
        $this->assertNull($result->forfeit_trust_status);
    }

    public function test_forfeit_rejects_an_invalid_fault(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_badfault');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->forfeit($deposit->id, 5000, 'x', null, 'nonsense');
    }

    public function test_confirm_fault_applies_the_hunters_trust_penalty(): void
    {
        $hunter  = $this->makeUser('hunter', 80);
        $deposit = $this->seedHeldFor(payerId: $hunter);
        $admin   = (string) Str::uuid();

        $service = $this->service();
        $service->forfeit($deposit->id, 5000, 'Damage', $admin, SecurityDepositService::FAULT_LESSEE, 'property_damage');
        $result = $service->confirmForfeitFault($deposit->id, $admin);

        $this->assertSame('applied', $result->forfeit_trust_status);
        $this->assertNotNull($result->forfeit_resolved_at);
        // -10 delta applied to the hunter.
        $this->assertSame(70, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
        $this->assertSame(1, DB::connection('identity')->table('trust_score_events')
            ->where('user_id', $hunter)->where('event_type', 'deposit_forfeited_against_user')->count());
    }

    public function test_waive_fault_clears_pending_without_penalty(): void
    {
        $hunter  = $this->makeUser('hunter', 80);
        $deposit = $this->seedHeldFor(payerId: $hunter);

        $service = $this->service();
        $service->forfeit($deposit->id, 5000, 'Disputed', (string) Str::uuid(), SecurityDepositService::FAULT_CONTESTED);
        $result = $service->waiveForfeitFault($deposit->id, (string) Str::uuid(), 'Hunter exonerated');

        $this->assertSame('waived', $result->forfeit_trust_status);
        // Score untouched.
        $this->assertSame(80, (int) DB::connection('identity')->table('users')->where('id', $hunter)->value('trust_score'));
        $this->assertSame(0, DB::connection('identity')->table('trust_score_events')->where('user_id', $hunter)->count());
    }

    public function test_confirm_requires_a_pending_decision(): void
    {
        $deposit = $this->seedHeld(5000, 'pi_nopending');

        $this->expectException(\RuntimeException::class);
        $this->service()->confirmForfeitFault($deposit->id);
    }

    // ── forfeiture oversight report ──────────────────────────────────────────────

    public function test_landowner_stats_flag_a_high_forfeiture_rate(): void
    {
        $scammer = $this->makeLandowner();
        // 4 forfeited + 1 released of 5 concluded = 80% rate, 4 forfeits → flagged.
        for ($i = 0; $i < 4; $i++) {
            $this->seedResolved($scammer, 'forfeited', 5000);
        }
        $this->seedResolved($scammer, 'released', 0);

        $honest = $this->makeLandowner();
        // 1 forfeited of 5 = 20%, below threshold → not flagged.
        $this->seedResolved($honest, 'forfeited', 5000);
        for ($i = 0; $i < 4; $i++) {
            $this->seedResolved($honest, 'released', 0);
        }

        $stats   = collect(app(SecurityDepositService::class)->landownerForfeitureStats())->keyBy('user_id');
        $flagged = $stats[$scammer];
        $clean   = $stats[$honest];

        $this->assertTrue($flagged['flagged']);
        $this->assertSame(4, $flagged['forfeits']);
        $this->assertSame(5, $flagged['resolved']);
        $this->assertEqualsWithDelta(0.8, $flagged['rate'], 0.001);

        $this->assertFalse($clean['flagged']);
        $this->assertEqualsWithDelta(0.2, $clean['rate'], 0.001);
    }
}
