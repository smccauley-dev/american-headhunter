<?php

namespace Tests\Feature\Billing;

use App\Models\Lease\Lease;
use App\Services\Billing\LeaseFinanceSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Landowner-facing payment summary surfaced on the lease + application detail pages.
 * Pure reads over real billing rows (owner role in tests bypasses RLS); no Stripe.
 */
class LeaseFinanceSummaryServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $bookingIds = [];
    /** @var array<int,string> */ private array $paymentIds = [];
    /** @var array<int,string> */ private array $securityIds = [];

    protected function tearDown(): void
    {
        $billing = DB::connection('billing');
        if ($this->bookingIds)  { $billing->table('booking_deposits')->whereIn('id', $this->bookingIds)->delete(); }
        if ($this->paymentIds)  { $billing->table('lease_payments')->whereIn('id', $this->paymentIds)->delete(); }
        if ($this->securityIds) { $billing->table('security_deposits')->whereIn('id', $this->securityIds)->delete(); }
        parent::tearDown();
    }

    private function lease(string $id, float $total = 1000): Lease
    {
        $lease = new Lease(['total_price' => $total]);
        $lease->id = $id; // id is guarded
        return $lease;
    }

    private function seedBooking(string $leaseId, int $amount, int $net, string $status = 'disbursed'): void
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('booking_deposits')->insert([
            'id'                       => $id,
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'amount_cents'             => $amount,
            'net_cents'                => $net,
            'currency'                 => 'USD',
            'status'                   => $status,
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'collected_at'             => in_array($status, ['collected', 'disbursed'], true) ? now() : null,
        ]);
        $this->bookingIds[] = $id;
    }

    private function seedPayment(string $leaseId, int $gross, int $surcharge, int $fee, int $net, string $status = 'collected'): void
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('lease_payments')->insert([
            'id'                       => $id,
            'lease_id'                 => $leaseId,
            'payer_user_id'            => (string) Str::uuid(),
            'payee_user_id'            => (string) Str::uuid(),
            'stripe_account_id'        => 'acct_' . Str::random(8),
            'gross_cents'              => $gross,
            'surcharge_cents'          => $surcharge,
            'application_fee_cents'    => $fee,
            'net_cents'                => $net,
            'currency'                 => 'USD',
            'status'                   => $status,
            'stripe_payment_intent_id' => 'pi_' . Str::random(12),
            'paid_at'                  => now(),
        ]);
        $this->paymentIds[] = $id;
    }

    private function seedSecurity(string $leaseId, int $amount, int $refunded = 0, int $forfeited = 0, string $status = 'held'): void
    {
        $id = (string) Str::uuid();
        DB::connection('billing')->table('security_deposits')->insert([
            'id'                     => $id,
            'lease_id'               => $leaseId,
            'payer_user_id'          => (string) Str::uuid(),
            'payee_user_id'          => (string) Str::uuid(),
            'amount_cents'           => $amount,
            'refunded_amount_cents'  => $refunded,
            'forfeited_amount_cents' => $forfeited,
            'currency'               => 'USD',
            'status'                 => $status,
        ]);
        $this->securityIds[] = $id;
    }

    public function test_assembles_booking_rent_and_security_breakdown(): void
    {
        $leaseId = (string) Str::uuid();
        $this->seedBooking($leaseId, 20000, 19000, 'disbursed');             // $200 paid, $190 net
        $this->seedPayment($leaseId, 31500, 1500, 2000, 28000, 'collected'); // $315 gross, $15 surcharge, $20 fee, $280 net
        $this->seedSecurity($leaseId, 50000, 0, 0, 'held');                  // $500 held

        $summary = app(LeaseFinanceSummaryService::class)->landownerSummary($this->lease($leaseId, 1000));

        // total - booking($200) - rent($300 = gross-surcharge) = $500 outstanding
        $this->assertSame('1,000.00', $summary['lease_total']);
        $this->assertSame('500.00', $summary['outstanding']);
        $this->assertSame('500.00', $summary['paid_to_date']);
        $this->assertFalse($summary['fully_paid']);
        // net = booking net $190 + payment net $280 = $470
        $this->assertSame('470.00', $summary['net_received']);

        $this->assertSame('200.00', $summary['booking_deposit']['amount']);
        $this->assertSame('190.00', $summary['booking_deposit']['net']);
        $this->assertTrue($summary['booking_deposit']['paid']);
        $this->assertSame('disbursed', $summary['booking_deposit']['status']);

        $this->assertCount(1, $summary['payments']);
        $this->assertSame('315.00', $summary['payments'][0]['amount']);
        $this->assertSame('20.00', $summary['payments'][0]['fee']);
        $this->assertSame('280.00', $summary['payments'][0]['net']);
        $this->assertSame('collected', $summary['payments'][0]['status']);

        $this->assertSame('500.00', $summary['security_deposit']['amount']);
        $this->assertSame('held', $summary['security_deposit']['status']);
    }

    public function test_full_payment_marks_fully_paid(): void
    {
        $leaseId = (string) Str::uuid();
        $this->seedPayment($leaseId, 100000, 0, 5000, 95000, 'collected'); // pays the whole $1000

        $summary = app(LeaseFinanceSummaryService::class)->landownerSummary($this->lease($leaseId, 1000));

        $this->assertTrue($summary['fully_paid']);
        $this->assertSame('0.00', $summary['outstanding']);
        $this->assertSame('1,000.00', $summary['paid_to_date']);
        $this->assertSame('950.00', $summary['net_received']);
    }

    public function test_unpaid_booking_deposit_contributes_no_net(): void
    {
        $leaseId = (string) Str::uuid();
        $this->seedBooking($leaseId, 20000, 0, 'pending'); // created but not yet paid

        $summary = app(LeaseFinanceSummaryService::class)->landownerSummary($this->lease($leaseId, 1000));

        $this->assertFalse($summary['booking_deposit']['paid']);
        $this->assertNull($summary['booking_deposit']['net']);
        $this->assertSame('0.00', $summary['net_received']);
        // an unpaid booking deposit is not credited toward the balance
        $this->assertSame('1,000.00', $summary['outstanding']);
    }

    public function test_no_billing_records_yields_empty_summary(): void
    {
        $summary = app(LeaseFinanceSummaryService::class)->landownerSummary($this->lease((string) Str::uuid(), 1000));

        $this->assertNull($summary['booking_deposit']);
        $this->assertNull($summary['security_deposit']);
        $this->assertSame([], $summary['payments']);
        $this->assertSame('0.00', $summary['net_received']);
        $this->assertSame('1,000.00', $summary['outstanding']);
        $this->assertSame('0.00', $summary['paid_to_date']);
    }
}
