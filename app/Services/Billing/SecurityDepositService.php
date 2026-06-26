<?php

namespace App\Services\Billing;

use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

/**
 * Lease security deposits (DB 4) — capture, hold, release, forfeit.
 *
 * Deposits are system-authored financial records: the runtime (member) path
 * never writes the row. A member's "Pay deposit" action only creates a Stripe
 * hosted Checkout session; the webhook (ah_system) authors the held row when the
 * one-time payment succeeds — the same shape as the subscription flow. Release
 * and forfeit are admin-driven (Filament panel runs under ah_system).
 *
 * Forfeiture records state, returns any un-forfeited remainder to the lessee, and
 * disburses the forfeited amount to the landowner via Stripe Connect / PayoutService
 * (best-effort — the cash stays captured until the landowner can receive payouts).
 */
class SecurityDepositService extends BaseService
{
    public function __construct(
        private readonly StripeService   $stripe,
        private readonly PropertyService $properties,
        private readonly AuditService    $audit,
        private readonly PayoutService   $payouts,
    ) {}

    // ── Read ────────────────────────────────────────────────────────────────────

    /**
     * Cents owed for a lease's security deposit, derived from its listing: a flat
     * deposit_amount when set, otherwise deposit_percent of the lease total. The
     * two are mutually exclusive on the listing. Returns 0 when none is configured.
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

        if ($listing->deposit_amount !== null && (float) $listing->deposit_amount > 0) {
            return (int) round((float) $listing->deposit_amount * 100);
        }

        if ($listing->deposit_percent !== null && (int) $listing->deposit_percent > 0) {
            return (int) round((float) $lease->total_price * (int) $listing->deposit_percent);
        }

        return 0;
    }

    /** The most recent deposit row for a lease, in any status (null when none). */
    public function forLease(string $leaseId): ?SecurityDeposit
    {
        return SecurityDeposit::where('lease_id', $leaseId)
            ->latest('created_at')
            ->first();
    }

    // ── Member-initiated capture (runtime — no local write) ──────────────────────

    /**
     * Create the hosted Checkout session a lessee pays to fund their deposit. The
     * row is authored later by the webhook; nothing is written here (the member
     * runs as ah_runtime, which cannot write security_deposits).
     *
     * @throws \RuntimeException when no deposit is due for the lease
     */
    public function createCheckoutSession(Lease $lease, User $payer, string $successUrl, string $cancelUrl): Session
    {
        $amountCents = $this->amountDueCents($lease);
        if ($amountCents <= 0) {
            throw new \RuntimeException("Lease {$lease->id} has no security deposit due.");
        }

        return $this->stripe->createDepositCheckoutSession(
            $payer,
            $amountCents,
            [
                'purpose'       => 'security_deposit',
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
     * Author the held deposit row from a completed payment-mode Checkout. Called
     * from the webhook. Idempotent on the captured PaymentIntent. Returns the row,
     * or null when the session isn't a security-deposit payment or is incomplete.
     *
     * @param array<string,mixed> $session Stripe checkout.session.completed payload
     */
    public function recordHeldFromCheckout(array $session): ?SecurityDeposit
    {
        $meta = $session['metadata'] ?? [];
        if (($meta['purpose'] ?? null) !== 'security_deposit') {
            return null;
        }

        $leaseId         = $meta['lease_id'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;
        if (! $leaseId || ! $paymentIntentId) {
            Log::warning('SecurityDeposit: checkout.session.completed missing fields', ['lease_id' => $leaseId]);
            return null;
        }

        $existing = SecurityDeposit::where('stripe_payment_intent_id', $paymentIntentId)->first();
        if ($existing) {
            return $existing; // replay
        }

        $amountCents = (int) ($meta['amount_cents'] ?? $session['amount_total'] ?? 0);

        $deposit = SecurityDeposit::create([
            'lease_id'                 => $leaseId,
            'payer_user_id'            => $meta['payer_user_id'] ?? null,
            'payee_user_id'            => $meta['payee_user_id'] ?? null,
            'amount_cents'             => $amountCents,
            'currency'                 => strtoupper((string) ($session['currency'] ?? 'USD')),
            'status'                   => 'held',
            'stripe_payment_intent_id' => $paymentIntentId,
            'held_at'                  => now(),
        ]);

        // Mirror the paid amount onto the lease (DB 3) for at-a-glance display.
        // Cross-DB write — best-effort; never fail the webhook over the mirror.
        rescue(function () use ($leaseId, $amountCents) {
            $lease = Lease::find($leaseId);
            if ($lease) {
                $lease->deposit_paid = $amountCents / 100;
                $lease->save();
            }
        });

        $this->audit->log(
            eventType:      'security_deposit.held',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $deposit->payer_user_id,
            actionSummary:  'Security deposit captured and held',
            newValues:      ['amount_cents' => $amountCents, 'status' => 'held'],
        );

        $this->invalidate("lease_detail:{$leaseId}");

        return $deposit;
    }

    /**
     * Return a held deposit to the lessee — refunds the full remaining balance and
     * marks it released. Admin-driven.
     *
     * @throws \RuntimeException when the deposit is not in the held state
     */
    public function release(string $depositId, ?string $actorUserId = null, ?string $note = null): SecurityDeposit
    {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->status !== 'held') {
            throw new \RuntimeException("Security deposit {$depositId} is not held (status {$deposit->status}).");
        }

        $remaining = $deposit->remainingCents();
        if ($remaining > 0 && $deposit->stripe_payment_intent_id) {
            $refund = $this->stripe->refundPaymentIntent($deposit->stripe_payment_intent_id, $remaining, $note);
            $deposit->stripe_refund_id      = $refund->id;
            $deposit->refunded_amount_cents = (int) $deposit->refunded_amount_cents + $remaining;
        }

        $deposit->status      = 'released';
        $deposit->released_at = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.released',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Security deposit released to lessee',
            newValues:      ['status' => 'released', 'refunded_amount_cents' => (int) $deposit->refunded_amount_cents],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * Forfeit some or all of a held deposit. Records the forfeited amount + reason,
     * returns any un-forfeited remainder to the lessee immediately, and disburses the
     * forfeited amount to the landowner via Stripe Connect (best-effort — see
     * disburseForfeitedAmount). Admin-driven.
     *
     * @throws \RuntimeException        when the deposit is not held
     * @throws \InvalidArgumentException when the amount is outside the remaining balance
     */
    public function forfeit(string $depositId, int $amountCents, string $reason, ?string $actorUserId = null): SecurityDeposit
    {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->status !== 'held') {
            throw new \RuntimeException("Security deposit {$depositId} is not held (status {$deposit->status}).");
        }

        $remaining = $deposit->remainingCents();
        if ($amountCents <= 0 || $amountCents > $remaining) {
            throw new \InvalidArgumentException("Forfeit amount must be between 1 and {$remaining} cents.");
        }

        $deposit->forfeited_amount_cents = (int) $deposit->forfeited_amount_cents + $amountCents;
        $deposit->forfeit_reason         = $reason;

        $returnCents = $remaining - $amountCents;
        if ($returnCents > 0 && $deposit->stripe_payment_intent_id) {
            $refund = $this->stripe->refundPaymentIntent(
                $deposit->stripe_payment_intent_id,
                $returnCents,
                'Security deposit partial return',
            );
            $deposit->stripe_refund_id      = $refund->id;
            $deposit->refunded_amount_cents = (int) $deposit->refunded_amount_cents + $returnCents;
            $deposit->released_at           = now();
            $deposit->status                = 'partially_released';
        } else {
            $deposit->status = 'forfeited';
        }

        $deposit->save();

        // Disburse the forfeited amount to the landowner via Stripe Connect. This is
        // best-effort: the forfeiture state is already recorded, so a Stripe failure
        // or a landowner who hasn't onboarded a payout account must not roll it back
        // — the captured cash simply stays on the platform until a later retry.
        $payoutId = $this->disburseForfeitedAmount($deposit, $amountCents);

        $this->audit->log(
            eventType:      'security_deposit.forfeited',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  $payoutId
                ? 'Security deposit forfeited and disbursed to landowner via Connect'
                : 'Security deposit forfeited (landowner payout pending Connect onboarding)',
            newValues:      [
                'status'                 => $deposit->status,
                'forfeited_amount_cents' => (int) $deposit->forfeited_amount_cents,
                'forfeit_reason'         => $reason,
                'payout_id'              => $payoutId,
            ],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * Transfer a forfeited deposit amount to the landowner (the deposit's payee).
     * Returns the payout id on success, or null when the landowner has no
     * payouts-enabled Connect account yet or the transfer fails — never throws, so
     * the forfeiture itself is never undone. PayoutService withholds the landowner's
     * tier platform fee like any other payout.
     */
    private function disburseForfeitedAmount(SecurityDeposit $deposit, int $amountCents): ?string
    {
        $landowner = User::on('identity')->find($deposit->payee_user_id);

        if (! $landowner || ! $this->payouts->canReceivePayouts($landowner)) {
            Log::info('Forfeited deposit payout deferred — landowner has no payouts-enabled account', [
                'security_deposit_id' => $deposit->id,
            ]);

            return null;
        }

        try {
            $payout = $this->payouts->disburse($landowner, $amountCents, [
                'security_deposit_id' => $deposit->id,
                'lease_id'            => $deposit->lease_id,
            ]);

            return $payout->id;
        } catch (\Throwable $e) {
            Log::error('Forfeited deposit payout failed', [
                'security_deposit_id' => $deposit->id,
                'error'               => $e->getMessage(),
            ]);

            return null;
        }
    }
}
