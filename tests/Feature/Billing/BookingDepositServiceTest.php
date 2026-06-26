<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\BookingDeposit;
use App\Models\Lease\Lease;
use App\Models\Property\PropertyListing;
use App\Services\Audit\AuditService;
use App\Services\Billing\BookingDepositService;
use App\Services\Billing\StripeService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Phase 5.x — non-refundable booking deposit. Stripe is mocked; the deposit rows
 * are real on the `billing` connection (owner role in tests bypasses RLS).
 * recordCollectedFromCheckout makes NO Stripe call — it only authors the row from a
 * completed Checkout payload. The booking deposit has no release/forfeit lifecycle.
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

    private function service(?StripeService $stripe = null, ?PropertyService $properties = null): BookingDepositService
    {
        return new BookingDepositService(
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
                'purpose'       => 'booking_deposit',
                'lease_id'      => $leaseId,
                'payer_user_id' => (string) Str::uuid(),
                'payee_user_id' => (string) Str::uuid(),
                'amount_cents'  => (string) $amount,
            ],
        ];
    }

    public function test_record_collected_creates_a_collected_deposit(): void
    {
        $pi      = 'pi_' . Str::random(14);
        $deposit = $this->service()->recordCollectedFromCheckout($this->checkoutPayload((string) Str::uuid(), $pi));

        $this->assertNotNull($deposit);
        $this->depositIds[] = $deposit->id;

        $this->assertSame('collected', $deposit->status);
        $this->assertSame(12500, (int) $deposit->amount_cents);
        $this->assertSame('USD', $deposit->currency);
        $this->assertNotNull($deposit->collected_at);
        $this->assertSame($pi, $deposit->stripe_payment_intent_id);
        $this->assertNull($deposit->payout_id, 'landowner payout is deferred — payout_id stays null');
    }

    public function test_record_collected_is_idempotent_on_payment_intent(): void
    {
        $service = $this->service();
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
}
