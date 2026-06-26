<?php

namespace App\Services\Billing;

use App\Models\Billing\BookingDeposit;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

/**
 * Lease booking deposits (DB 4) — the non-refundable down payment the hunter pays
 * at signing, alongside the refundable security deposit. Unlike SecurityDeposit it
 * has no release/forfeit lifecycle: it is earned on booking, credited toward the
 * lease total, and disbursed to the landowner (minus platform fee).
 *
 * System-authored, like SecurityDeposit: the runtime (member) path never writes the
 * row. A member's "Pay booking deposit" action only creates a Stripe hosted
 * Checkout session; the webhook (ah_system) authors the collected row when the
 * one-time payment succeeds.
 *
 * Disbursement to the landowner is DEFERRED — PayoutService / Stripe Connect is not
 * on this branch, so a collected deposit stays captured on the platform (payout_id
 * NULL, status 'collected') until the Connect work lands and settles it. This is the
 * same deferral the security-deposit forfeiture path already uses.
 */
class BookingDepositService extends BaseService
{
    public function __construct(
        private readonly StripeService   $stripe,
        private readonly PropertyService $properties,
        private readonly AuditService    $audit,
    ) {}

    // ── Read ────────────────────────────────────────────────────────────────────

    /**
     * Cents owed for a lease's booking deposit, derived from its listing: a flat
     * booking_deposit_amount when set, otherwise booking_deposit_percent of the lease
     * total. The two are mutually exclusive. Returns 0 when none is configured.
     */
    public function amountDueCents(Lease $lease): int
    {
        if (! $lease->listing_id) {
            return 0;
        }

        $listing = $this->properties->findListing($lease->listing_id);
        if (! $listing) {
            return 0;
        }

        if ($listing->booking_deposit_amount !== null && (float) $listing->booking_deposit_amount > 0) {
            return (int) round((float) $listing->booking_deposit_amount * 100);
        }

        if ($listing->booking_deposit_percent !== null && (int) $listing->booking_deposit_percent > 0) {
            return (int) round((float) $lease->total_price * (int) $listing->booking_deposit_percent);
        }

        return 0;
    }

    /** The most recent booking deposit row for a lease, in any status (null when none). */
    public function forLease(string $leaseId): ?BookingDeposit
    {
        return BookingDeposit::where('lease_id', $leaseId)
            ->latest('created_at')
            ->first();
    }

    // ── Member-initiated capture (runtime — no local write) ──────────────────────

    /**
     * Create the hosted Checkout session a lessee pays to fund their booking deposit.
     * The row is authored later by the webhook; nothing is written here (the member
     * runs as ah_runtime, which cannot write booking_deposits).
     *
     * @throws \RuntimeException when no booking deposit is due for the lease
     */
    public function createCheckoutSession(Lease $lease, User $payer, string $successUrl, string $cancelUrl): Session
    {
        $amountCents = $this->amountDueCents($lease);
        if ($amountCents <= 0) {
            throw new \RuntimeException("Lease {$lease->id} has no booking deposit due.");
        }

        return $this->stripe->createDepositCheckoutSession(
            $payer,
            $amountCents,
            [
                'purpose'       => 'booking_deposit',
                'lease_id'      => $lease->id,
                'payer_user_id' => $lease->lessee_user_id,
                'payee_user_id' => $lease->lessor_user_id,
                'amount_cents'  => (string) $amountCents,
            ],
            $successUrl,
            $cancelUrl,
        );
    }

    // ── System writes (webhook + admin run as ah_system) ─────────────────────────

    /**
     * Author the collected booking-deposit row from a completed payment-mode
     * Checkout. Called from the webhook. Idempotent on the captured PaymentIntent.
     * Returns the row, or null when the session isn't a booking-deposit payment or is
     * incomplete. The landowner payout is deferred (payout_id stays NULL).
     *
     * @param array<string,mixed> $session Stripe checkout.session.completed payload
     */
    public function recordCollectedFromCheckout(array $session): ?BookingDeposit
    {
        $meta = $session['metadata'] ?? [];
        if (($meta['purpose'] ?? null) !== 'booking_deposit') {
            return null;
        }

        $leaseId         = $meta['lease_id'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;
        if (! $leaseId || ! $paymentIntentId) {
            Log::warning('BookingDeposit: checkout.session.completed missing fields', ['lease_id' => $leaseId]);
            return null;
        }

        $existing = BookingDeposit::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($existing) {
            return $existing; // replay
        }

        $amountCents = (int) ($meta['amount_cents'] ?? $session['amount_total'] ?? 0);

        $deposit = BookingDeposit::create([
            'lease_id'                 => $leaseId,
            'payer_user_id'            => $meta['payer_user_id'] ?? null,
            'payee_user_id'            => $meta['payee_user_id'] ?? null,
            'amount_cents'             => $amountCents,
            'currency'                 => strtoupper((string) ($session['currency'] ?? 'USD')),
            'status'                   => 'collected',
            'stripe_payment_intent_id' => $paymentIntentId,
            'collected_at'             => now(),
        ]);

        // Mirror the paid amount onto the lease (DB 3) so the remaining balance
        // (total_price - booking_deposit_paid) renders at a glance. Cross-DB write —
        // best-effort; never fail the webhook over the mirror.
        rescue(function () use ($leaseId, $amountCents) {
            $lease = Lease::find($leaseId);
            if ($lease) {
                $lease->booking_deposit_paid = $amountCents / 100;
                $lease->save();
            }
        });

        $this->audit->log(
            eventType:      'booking_deposit.collected',
            sourceDatabase: 'ah_billing',
            tableName:      'booking_deposits',
            recordId:       $deposit->id,
            userId:         $deposit->payer_user_id,
            actionSummary:  'Booking deposit collected (landowner payout deferred to Connect)',
            newValues:      ['amount_cents' => $amountCents, 'status' => 'collected'],
        );

        $this->invalidate("lease_detail:{$leaseId}");

        return $deposit;
    }
}
