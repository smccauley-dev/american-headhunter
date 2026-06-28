<?php

namespace App\Services\Billing;

use App\Models\Billing\BookingDeposit;
use App\Models\Billing\LeasePayment;
use App\Models\Billing\SecurityDeposit;
use App\Models\Lease\Lease;

/**
 * Read-only, landowner-facing money summary for a single lease: what the hunter has
 * paid (booking deposit + lease rent), the landowner's net of each after the platform
 * fee/surcharge, the outstanding balance, and where the refundable security deposit
 * stands. Surfaced on the landowner's application-detail and lease-detail pages, which
 * previously showed the lease total but no indication of payment.
 *
 * The landowner is the payee on all three billing record types (booking_deposits,
 * lease_payments, security_deposits) and every table's RLS SELECT policy admits either
 * party, so these reads succeed under ah_runtime for the landowner (and under the
 * ah_system scope the application-detail assembly already runs in).
 *
 * Pure reads — no Stripe calls, no writes. Money is returned as formatted dollar
 * strings ("1,160.00"); the caller prepends the currency symbol.
 */
class LeaseFinanceSummaryService
{
    public function __construct(
        private readonly LeasePaymentService $leasePayments,
    ) {}

    /** @return array<string,mixed> */
    public function landownerSummary(Lease $lease): array
    {
        $totalCents       = (int) round((float) $lease->total_price * 100);
        $outstandingCents = $this->leasePayments->balanceDueCents($lease);
        $paidCents        = max(0, $totalCents - $outstandingCents);

        $booking  = $this->bookingDeposit($lease);
        $payments = $this->payments($lease);

        $netReceivedCents = ($booking['net_cents'] ?? 0) + array_sum(array_column($payments, 'net_cents'));

        return [
            'lease_total'      => $this->dollars($totalCents),
            'paid_to_date'     => $this->dollars($paidCents),
            'outstanding'      => $this->dollars($outstandingCents),
            'fully_paid'       => $outstandingCents <= 0,
            'net_received'     => $this->dollars($netReceivedCents),
            'booking_deposit'  => $booking ? $this->shapeBooking($booking) : null,
            'security_deposit' => $this->securityDeposit($lease),
            'payments'         => array_map(fn (array $p) => [
                'amount'  => $this->dollars($p['gross_cents']),
                'fee'     => $this->dollars($p['fee_cents']),
                'net'     => $this->dollars($p['net_cents']),
                'status'  => $p['status'],
                'paid_at' => $p['paid_at'],
            ], $payments),
        ];
    }

    /**
     * The single booking deposit for a lease (or null), with the landowner's net
     * resolved best-effort: the Connect-era net_cents when present, else the gross
     * once paid (legacy platform-held deposits never recorded a net).
     *
     * @return array{status:?string, paid:bool, gross_cents:int, net_cents:int, collected_at:?\Illuminate\Support\Carbon}|null
     */
    private function bookingDeposit(Lease $lease): ?array
    {
        $bd = BookingDeposit::where('lease_id', $lease->id)->latest('created_at')->first();
        if (! $bd) {
            return null;
        }

        $paid = in_array($bd->status, ['collected', 'disbursed'], true);

        return [
            'status'       => $bd->status,
            'paid'         => $paid,
            'gross_cents'  => (int) $bd->amount_cents,
            'net_cents'    => $paid ? (int) ($bd->net_cents ?? $bd->amount_cents) : 0,
            'collected_at' => $bd->collected_at,
        ];
    }

    /** @param array{status:?string, paid:bool, gross_cents:int, net_cents:int, collected_at:?\Illuminate\Support\Carbon} $bd */
    private function shapeBooking(array $bd): array
    {
        return [
            'status'       => $bd['status'],
            'paid'         => $bd['paid'],
            'amount'       => $this->dollars($bd['gross_cents']),
            'net'          => $bd['paid'] ? $this->dollars($bd['net_cents']) : null,
            'collected_at' => $bd['collected_at']?->format('M j, Y'),
        ];
    }

    /**
     * Lease-rent payments for the lease, newest first. Returns the raw cents the
     * landowner summary aggregates over, plus a formatted paid_at.
     *
     * @return array<int, array{gross_cents:int, fee_cents:int, net_cents:int, status:string, paid_at:?string}>
     */
    private function payments(Lease $lease): array
    {
        return $this->leasePayments->forLease($lease->id)->map(fn (LeasePayment $p) => [
            'gross_cents' => (int) $p->gross_cents,
            'fee_cents'   => (int) $p->application_fee_cents,
            // Only collected/partially-refunded payments count toward net received.
            'net_cents'   => in_array($p->status, ['collected', 'partially_refunded'], true) ? (int) $p->net_cents : 0,
            'status'      => $p->status,
            'paid_at'     => $p->paid_at?->format('M j, Y'),
        ])->values()->all();
    }

    /**
     * The refundable security deposit's standing (or null). This is held collateral,
     * not lease income, so it is reported separately and never folded into net_received.
     *
     * @return array{status:?string, amount:string, refunded:string, forfeited:string}|null
     */
    private function securityDeposit(Lease $lease): ?array
    {
        $sd = SecurityDeposit::where('lease_id', $lease->id)->latest('created_at')->first();
        if (! $sd) {
            return null;
        }

        return [
            'status'    => $sd->status,
            'amount'    => $this->dollars((int) $sd->amount_cents),
            'refunded'  => $this->dollars((int) $sd->refunded_amount_cents),
            'forfeited' => $this->dollars((int) $sd->forfeited_amount_cents),
        ];
    }

    private function dollars(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
