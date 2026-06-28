<?php

namespace App\Services\Billing;

use App\Database\ConnectionRole;
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
 * Collected via a Stripe Connect destination charge: the customer pays the deposit
 * on the platform account, transfer_data[destination] routes the net to the
 * landowner's connected account at charge time and application_fee_amount is the
 * platform's cut — so the deposit settles to the landowner immediately (status
 * 'disbursed', no separate payout) rather than sitting captured on the platform.
 */
class BookingDepositService extends BaseService
{
    public function __construct(
        private readonly StripeService   $stripe,
        private readonly PayoutService   $payouts,
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
     * Create the hosted Checkout session a lessee pays to fund their booking deposit
     * as a destination charge to the landowner. The customer pays the deposit amount;
     * the platform keeps the tier fee (application_fee) and the landowner's net is
     * transferred at charge time. The row is authored later by the webhook; nothing is
     * written here (the member runs as ah_runtime, which cannot write booking_deposits).
     *
     * @throws \RuntimeException when no booking deposit is due, or the landowner can't yet take charges
     */
    public function createCheckoutSession(Lease $lease, User $payer, string $successUrl, string $cancelUrl): Session
    {
        $amountCents = $this->amountDueCents($lease);
        if ($amountCents <= 0) {
            throw new \RuntimeException("Lease {$lease->id} has no booking deposit due.");
        }

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
            throw new \RuntimeException('The landowner has not finished payout setup, so the booking deposit cannot be paid yet.');
        }

        $tier    = ConnectionRole::asSystem(fn () => $this->payouts->quote($landowner, $amountCents));
        $feeCents = $tier['fee_cents'];

        $property    = rescue(fn () => $this->properties->find($lease->property_id), null);
        $productName = 'Booking deposit — ' . ($property?->title ?? 'hunting lease');

        return $this->stripe->createConnectCheckoutSession(
            $payer,
            $amountCents,
            $feeCents,
            $account->stripe_account_id,
            [
                'purpose'               => 'booking_deposit',
                'lease_id'              => $lease->id,
                'payer_user_id'         => $lease->lessee_user_id,
                'payee_user_id'         => $lease->lessor_user_id,
                'stripe_account_id'     => $account->stripe_account_id,
                'amount_cents'          => (string) $amountCents,
                'application_fee_cents' => (string) $feeCents,
                'net_cents'             => (string) $tier['net_cents'],
            ],
            $successUrl,
            $cancelUrl,
            $productName,
        );
    }

    // ── System writes (webhook + admin run as ah_system) ─────────────────────────

    /**
     * Author the booking-deposit row from a completed payment-mode Checkout. Called
     * from the webhook. Idempotent on the captured PaymentIntent. Returns the row, or
     * null when the session isn't a booking-deposit payment or is incomplete. The
     * destination transfer settles the landowner's net at charge time, so the row is
     * recorded 'disbursed' with the auto-created transfer id.
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

        // The destination transfer is auto-created by Stripe; capture its id best-effort
        // — the money already moved, so never fail the webhook over the read.
        $ids = rescue(
            fn () => $this->stripe->chargeAndTransferForPaymentIntent($paymentIntentId),
            ['charge_id' => null, 'transfer_id' => null],
        );

        $deposit = BookingDeposit::create([
            'lease_id'                 => $leaseId,
            'payer_user_id'            => $meta['payer_user_id'] ?? null,
            'payee_user_id'            => $meta['payee_user_id'] ?? null,
            'stripe_account_id'        => $meta['stripe_account_id'] ?? null,
            'amount_cents'             => $amountCents,
            'application_fee_cents'    => (int) ($meta['application_fee_cents'] ?? 0),
            'net_cents'                => (int) ($meta['net_cents'] ?? 0),
            'currency'                 => strtoupper((string) ($session['currency'] ?? 'USD')),
            'status'                   => 'disbursed',
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_transfer_id'       => $ids['transfer_id'],
            'collected_at'             => now(),
            'disbursed_at'             => now(),
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
            actionSummary:  'Booking deposit collected and disbursed to landowner via Stripe Connect destination charge',
            newValues:      ['amount_cents' => $amountCents, 'status' => 'disbursed'],
        );

        $this->invalidate("lease_detail:{$leaseId}");

        return $deposit;
    }
}
