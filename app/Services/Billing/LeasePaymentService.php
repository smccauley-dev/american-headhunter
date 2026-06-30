<?php

namespace App\Services\Billing;

use App\Database\ConnectionRole;
use App\Models\Billing\BookingDeposit;
use App\Models\Billing\LeasePayment;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

/**
 * Lease-rent collection via Stripe Connect destination charges (DB 4). The customer
 * pays on the platform account; transfer_data[destination] routes the net to the
 * landowner's connected account, application_fee_amount is the platform's cut, and
 * on_behalf_of attributes settlement + the 1099-K to the landowner. There is no
 * separate disbursement step — the landowner is paid at charge time.
 *
 * System-authored, like the deposit services: the runtime (member) path only creates
 * a hosted Checkout session; the webhook (or the db.system success-return) authors
 * the collected lease_payment row (ah_runtime cannot write lease_payments).
 */
class LeasePaymentService extends BaseService
{
    public function __construct(
        private readonly StripeService   $stripe,
        private readonly PayoutService   $payouts,
        private readonly FeeService      $fees,
        private readonly PropertyService $properties,
        private readonly AuditService    $audit,
    ) {}

    // ── Read ────────────────────────────────────────────────────────────────────

    /**
     * Cents still owed on a lease: its total, less any booking deposit credited
     * toward it, less the rent already collected through prior lease payments (the
     * processing surcharge does not count toward the lease total). Never negative.
     */
    public function balanceDueCents(Lease $lease): int
    {
        $totalCents = (int) round((float) $lease->total_price * 100);

        // 'held' is the vet-first booking fee paid before this lease existed — it is
        // already captured and credits toward the total. 'collected'/'disbursed' linger
        // from the pre-vet destination-charge model.
        $bookingCollected = (int) BookingDeposit::where('lease_id', $lease->id)
            ->whereIn('status', ['held', 'collected', 'disbursed'])
            ->sum('amount_cents');

        $rentPaid = $this->collectedFor($lease->id)
            ->sum(fn (LeasePayment $p) => $p->gross_cents - $p->surcharge_cents);

        return max(0, $totalCents - $bookingCollected - (int) $rentPaid);
    }

    /** All lease payments for a lease that still count as paid (collected or partially refunded). */
    public function collectedFor(string $leaseId): Collection
    {
        return LeasePayment::where('lease_id', $leaseId)
            ->whereIn('status', ['collected', 'partially_refunded'])
            ->get();
    }

    /** Every lease payment for a lease, newest first (admin + member history). */
    public function forLease(string $leaseId): Collection
    {
        return LeasePayment::where('lease_id', $leaseId)
            ->latest('created_at')
            ->get();
    }

    /**
     * The fee breakdown a member would pay to settle the current balance: the rent
     * balance, the tier platform fee on it, the processing surcharge, the gross the
     * customer pays, and the landowner's net. Drives the "Pay lease balance" quote.
     *
     * @return array{balance_cents:int, fee_pct:float, fee_cents:int, surcharge_cents:int, gross_cents:int, net_cents:int}
     */
    public function quote(Lease $lease, User $landowner): array
    {
        $balance   = $this->balanceDueCents($lease);
        // The landowner's fee tier reads their subscription/promo rows, which RLS
        // scopes to the landowner — invisible to a paying lessee under ah_runtime.
        // Resolve it under ah_system so the lessee sees the correct (grandfathered)
        // fee instead of silently falling back to the higher free-tier rate.
        $tier      = ConnectionRole::asSystem(fn () => $this->payouts->quote($landowner, $balance));
        $surcharge = $this->surchargeFor($lease, $balance);

        return [
            'balance_cents'   => $balance,
            'fee_pct'         => $tier['fee_pct'],
            'fee_cents'       => $tier['fee_cents'],
            'surcharge_cents' => $surcharge,
            'gross_cents'     => $balance + $surcharge,
            'net_cents'       => $tier['net_cents'],
        ];
    }

    // ── Member-initiated capture (runtime — no local write) ──────────────────────

    /**
     * Create the hosted Checkout session a lessee pays to settle their lease balance
     * as a destination charge to the landowner. Nothing is written here (the member
     * runs as ah_runtime, which cannot write lease_payments) — the row is authored
     * later by the webhook / success-return.
     *
     * @throws \RuntimeException when no balance is due or the landowner cannot yet take charges
     */
    public function createCheckoutSession(Lease $lease, User $payer, string $successUrl, string $cancelUrl): Session
    {
        // Under ah_runtime the paying lessee cannot read the landowner as themselves:
        // RLS hides the landowner's identity row (getLessor) and their Connect account
        // (SEC-045/055), and their fee tier reads the landowner's subscription/promo
        // rows. A lessee party is legitimately allowed to pay this landowner, so resolve
        // all three under ah_system without broadening their general read access.
        $landowner = ConnectionRole::asSystem(fn () => $lease->getLessor());
        if (! $landowner) {
            throw new \RuntimeException("Lease {$lease->id} has no landowner to pay.");
        }

        $account = ConnectionRole::asSystem(fn () => $this->payouts->connectAccount($landowner));
        if ($account === null || ! $account->charges_enabled) {
            throw new \RuntimeException('The landowner has not finished payout setup, so the lease balance cannot be paid yet.');
        }

        $balance = $this->balanceDueCents($lease);
        if ($balance <= 0) {
            throw new \RuntimeException("Lease {$lease->id} has no balance due.");
        }

        $tier        = ConnectionRole::asSystem(fn () => $this->payouts->quote($landowner, $balance));
        $surcharge   = $this->surchargeFor($lease, $balance);
        $grossCents  = $balance + $surcharge;
        $appFeeCents = $tier['fee_cents'] + $surcharge;

        $property    = rescue(fn () => $this->properties->find($lease->property_id), null);
        $productName = 'Lease balance — ' . ($property?->title ?? 'hunting lease');

        return $this->stripe->createConnectCheckoutSession(
            $payer,
            $grossCents,
            $appFeeCents,
            $account->stripe_account_id,
            [
                'purpose'               => 'lease_payment',
                'lease_id'              => $lease->id,
                'payer_user_id'         => $lease->lessee_user_id,
                'payee_user_id'         => $lease->lessor_user_id,
                'stripe_account_id'     => $account->stripe_account_id,
                'gross_cents'           => (string) $grossCents,
                'surcharge_cents'       => (string) $surcharge,
                'application_fee_cents' => (string) $appFeeCents,
                'net_cents'             => (string) $tier['net_cents'],
            ],
            $successUrl,
            $cancelUrl,
            $productName,
        );
    }

    // ── System writes (webhook + admin run as ah_system) ─────────────────────────

    /**
     * Author the collected lease-payment row from a completed payment-mode Checkout.
     * Called from the webhook and the db.system success-return. Idempotent on the
     * captured PaymentIntent. Returns the row, or null when the session isn't a
     * lease payment or is incomplete.
     *
     * @param array<string,mixed> $session Stripe checkout.session.completed payload
     */
    public function recordCollectedFromCheckout(array $session): ?LeasePayment
    {
        $meta = $session['metadata'] ?? [];
        if (($meta['purpose'] ?? null) !== 'lease_payment') {
            return null;
        }

        // Only a genuinely paid session may author a collected row. The signed
        // webhook fires only on completion, but the db.system success-return takes a
        // user-supplied session_id — without this gate an abandoned (unpaid) session
        // could be replayed to fake a paid balance (SEC-058). In payment mode the
        // PaymentIntent id is present before payment, so it can't stand in for this.
        // 'no_payment_required' covers any future $0 balance.
        $paymentStatus = $session['payment_status'] ?? null;
        if (! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            Log::warning('LeasePayment: ignoring non-paid checkout session', ['session_id' => $session['id'] ?? null, 'payment_status' => $paymentStatus]);
            return null;
        }

        $leaseId         = $meta['lease_id'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;
        if (! $leaseId || ! $paymentIntentId) {
            Log::warning('LeasePayment: checkout.session.completed missing fields', ['lease_id' => $leaseId]);
            return null;
        }

        $existing = LeasePayment::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($existing) {
            return $existing; // replay
        }

        // The destination transfer is auto-created by Stripe; capture both ids
        // best-effort — the money is already collected, so never fail over the read.
        $ids = rescue(
            fn () => $this->stripe->chargeAndTransferForPaymentIntent($paymentIntentId),
            ['charge_id' => null, 'transfer_id' => null],
        );

        $grossCents = (int) ($meta['gross_cents'] ?? $session['amount_total'] ?? 0);

        $payment = LeasePayment::create([
            'lease_id'                 => $leaseId,
            'payer_user_id'            => $meta['payer_user_id'] ?? null,
            'payee_user_id'            => $meta['payee_user_id'] ?? null,
            'stripe_account_id'        => $meta['stripe_account_id'] ?? '',
            'gross_cents'              => $grossCents,
            'surcharge_cents'          => (int) ($meta['surcharge_cents'] ?? 0),
            'application_fee_cents'    => (int) ($meta['application_fee_cents'] ?? 0),
            'net_cents'                => (int) ($meta['net_cents'] ?? 0),
            'currency'                 => strtoupper((string) ($session['currency'] ?? 'USD')),
            'status'                   => 'collected',
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_charge_id'         => $ids['charge_id'],
            'stripe_transfer_id'       => $ids['transfer_id'],
            'paid_at'                  => now(),
        ]);

        $this->audit->log(
            eventType:      'lease_payment.collected',
            sourceDatabase: 'ah_billing',
            tableName:      'lease_payments',
            recordId:       $payment->id,
            userId:         $payment->payer_user_id,
            actionSummary:  'Lease payment collected via Stripe Connect destination charge',
            newValues:      [
                'gross_cents'           => $payment->gross_cents,
                'application_fee_cents' => $payment->application_fee_cents,
                'net_cents'             => $payment->net_cents,
                'status'                => 'collected',
            ],
        );

        $this->invalidate("lease_detail:{$leaseId}");

        // A signed lease is held in 'pending_payment' until its balance is settled;
        // this payment may be the one that clears it. Promote to 'active' so field
        // access (check-in, gate QR, stand map — all gate on 'active') unlocks.
        $this->activateIfFullyPaid($leaseId);

        return $payment;
    }

    /**
     * Promote a signed-but-unpaid lease to active once its balance reaches zero.
     * No-op unless the lease is currently 'pending_payment' and nothing is owed.
     * Best-effort — an activation hiccup must never fail the payment recording.
     */
    private function activateIfFullyPaid(string $leaseId): void
    {
        $lease = Lease::find($leaseId);
        if (! $lease || $lease->status !== 'pending_payment') {
            return;
        }
        if ($this->balanceDueCents($lease) > 0) {
            return;
        }

        rescue(fn () => app(\App\Services\Lease\LeaseService::class)->activate($leaseId));
    }

    /**
     * Refund a lease payment, reversing the destination transfer and the platform
     * fee (see StripeService::refundDestinationCharge). A null amount refunds in full
     * (status → refunded); a partial amount marks it partially_refunded. Admin-only.
     */
    public function refund(LeasePayment $payment, ?int $amountCents = null, ?string $actorUserId = null): LeasePayment
    {
        if ($payment->status === 'refunded') {
            throw new \RuntimeException("Lease payment {$payment->id} is already fully refunded.");
        }

        $this->stripe->refundDestinationCharge($payment->stripe_payment_intent_id, $amountCents);

        $payment->status = ($amountCents === null || $amountCents >= $payment->gross_cents)
            ? 'refunded'
            : 'partially_refunded';
        $payment->save();

        $this->audit->log(
            eventType:      'lease_payment.refunded',
            sourceDatabase: 'ah_billing',
            tableName:      'lease_payments',
            recordId:       $payment->id,
            userId:         $actorUserId,
            actionSummary:  'Lease payment refunded (transfer + application fee reversed)',
            newValues:      ['refunded_cents' => $amountCents ?? $payment->gross_cents, 'status' => $payment->status],
        );

        $this->invalidate("lease_detail:{$payment->lease_id}");

        return $payment;
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /** The customer-paid processing surcharge for a lease payment of the given rent. */
    private function surchargeFor(Lease $lease, int $rentCents): int
    {
        $stateCode = rescue(fn () => $this->properties->find($lease->property_id)?->state_code, null);

        // Category 'lease' — the value the fee_schedules CHECK + the Processing Fees
        // admin editor support (there is no 'lease_payment' category).
        return $this->fees->processingFee('lease', $stateCode, $rentCents)['fee_cents'];
    }
}
