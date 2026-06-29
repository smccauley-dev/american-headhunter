<?php

namespace App\Services\Billing;

use App\Database\ConnectionRole;
use App\Models\Billing\BookingDeposit;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Models\Lease\LeaseApplication;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Lease\ApplicationService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

/**
 * Vet-first booking fee (DB 4). The booking fee is application-scoped: the landowner
 * approves an applicant first (no money), which opens a 24-hour window to pay this
 * fee and claim the spot. The first approved applicant to pay wins; the lease is
 * created at that moment (see ApplicationService::onBookingFeePaid).
 *
 * The fee is HELD on the platform (a plain charge, not a destination charge) and
 * routed on outcome:
 *  - 'held'      — paid, lease created, awaiting completion (credited toward the lease)
 *  - 'disbursed' — lease activated; the fee was released to the landowner (minus fee)
 *  - 'forfeited' — the 7-day completion window lapsed; the fee went to the landowner
 *  - 'refunded'  — the payer lost the first-to-pay race; the fee was returned
 *
 * System-authored: the runtime (apply-portal) path never writes the row. The
 * applicant's "Pay booking fee" action only creates a Stripe hosted Checkout session;
 * the webhook (ah_system) authors the held row and drives the win/lose outcome.
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
     * Cents owed for an application's booking fee, derived from its listing: a flat
     * booking_deposit_amount when set, otherwise booking_deposit_percent of the listing
     * total. (total_price is in dollars, so dollars × percent already yields cents.)
     * Returns 0 when none is configured.
     */
    public function amountDueForApplication(LeaseApplication $application): int
    {
        $listing = $this->properties->findListing($application->listing_id);
        if (! $listing) {
            return 0;
        }

        if ($listing->booking_deposit_amount !== null && (float) $listing->booking_deposit_amount > 0) {
            return (int) round((float) $listing->booking_deposit_amount * 100);
        }

        if ($listing->booking_deposit_percent !== null && (int) $listing->booking_deposit_percent > 0) {
            return (int) round((float) ($listing->price_total ?? 0) * (int) $listing->booking_deposit_percent);
        }

        return 0;
    }

    /** The most recent booking-fee row for an application, in any status (null when none). */
    public function forApplication(string $applicationId): ?BookingDeposit
    {
        return BookingDeposit::where('application_id', $applicationId)
            ->latest('created_at')
            ->first();
    }

    /** The most recent booking-fee row backing a lease (the winning payment). */
    public function forLease(string $leaseId): ?BookingDeposit
    {
        return BookingDeposit::where('lease_id', $leaseId)
            ->latest('created_at')
            ->first();
    }

    // ── Applicant-initiated payment (runtime — no local write) ───────────────────

    /**
     * Create the hosted Checkout session an approved applicant pays to claim the spot.
     * The fee is HELD on the platform (a plain charge); the win/lose routing happens
     * later. The row is authored by the webhook; nothing is written here (the applicant
     * runs as ah_runtime, which cannot write booking_deposits).
     *
     * @throws \RuntimeException when the booking window is closed or no fee is due
     */
    public function createCheckoutSession(LeaseApplication $application, User $payer, string $successUrl, string $cancelUrl): Session
    {
        if (! $application->bookingWindowOpen()) {
            throw new \RuntimeException('The booking-fee window for this application is closed.');
        }

        $amountCents = $this->amountDueForApplication($application);
        if ($amountCents <= 0) {
            throw new \RuntimeException("Application {$application->id} has no booking fee due.");
        }

        // Resolve the landowner (payee) so the row can later route the held fee to them.
        // The applicant can't read the property/owner as themselves under RLS, so resolve
        // under ah_system without broadening their general read access.
        $payeeUserId = ConnectionRole::asSystem(fn () => $this->resolveLandownerId($application));

        $title       = $application->property_title_snapshot
            ?? ConnectionRole::asSystem(fn () => $this->properties->findListing($application->listing_id)?->property?->title);
        $productName = 'Booking fee — ' . ($title ?? 'hunting lease');

        return $this->stripe->createDepositCheckoutSession(
            $payer,
            $amountCents,
            [
                'purpose'        => 'booking_fee',
                'application_id' => $application->id,
                'listing_id'     => $application->listing_id,
                'payer_user_id'  => $payer->id,
                'payee_user_id'  => $payeeUserId,
                'amount_cents'   => (string) $amountCents,
            ],
            $successUrl,
            $cancelUrl,
            $productName,
        );
    }

    // ── System writes (webhook + return reconcile run as ah_system) ──────────────

    /**
     * Author the held booking-fee row from a completed payment-mode Checkout and drive
     * the win/lose outcome. Called from the webhook (and the success-return reconcile).
     *
     * Idempotent and re-drivable: the held row is created first so a hard failure
     * mid-win can be retried by Stripe's webhook redelivery; it only short-circuits once
     * the outcome is settled (a lease is attached, or the row is no longer 'held').
     * On a win the lease_id is backfilled; on a loss (the reservation race) the fee is
     * refunded and the row flipped to 'refunded'.
     *
     * @param array<string,mixed> $session Stripe checkout.session.completed payload
     */
    public function recordPaidFromCheckout(array $session): ?BookingDeposit
    {
        $meta = $session['metadata'] ?? [];
        if (($meta['purpose'] ?? null) !== 'booking_fee') {
            return null;
        }

        $applicationId   = $meta['application_id'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;
        if (! $applicationId || ! $paymentIntentId) {
            Log::warning('BookingFee: checkout.session.completed missing fields', ['application_id' => $applicationId]);

            return null;
        }

        $existing = BookingDeposit::where('stripe_payment_intent_id', $paymentIntentId)->first();
        // Already settled (won → has a lease; or refunded/disbursed/forfeited) → replay.
        if ($existing && ($existing->lease_id || ! $existing->isHeld())) {
            return $existing;
        }

        $amountCents = (int) ($meta['amount_cents'] ?? $session['amount_total'] ?? 0);

        // Capture the held charge id best-effort — the source for a later transfer/refund.
        $chargeId = rescue(fn () => $this->stripe->chargeIdForPaymentIntent($paymentIntentId), null);

        $deposit = $existing ?? BookingDeposit::create([
            'application_id'           => $applicationId,
            'lease_id'                 => null,
            'payer_user_id'            => $meta['payer_user_id'] ?? null,
            'payee_user_id'            => $meta['payee_user_id'] ?? null,
            'amount_cents'             => $amountCents,
            'currency'                 => strtoupper((string) ($session['currency'] ?? 'USD')),
            'status'                   => 'held',
            'stripe_payment_intent_id' => $paymentIntentId,
            'stripe_charge_id'         => $chargeId,
            'collected_at'             => now(),
        ]);

        // Claim the spot: create the lease + reservation + signing request, or lose the race.
        $result = app(ApplicationService::class)->onBookingFeePaid($applicationId, $deposit->payer_user_id);

        if ($result['outcome'] === 'won' && $result['lease']) {
            $lease = $result['lease'];

            $deposit->lease_id = $lease->id;
            $deposit->save();

            // Mirror the held fee onto the lease so it credits toward the balance due.
            rescue(function () use ($lease, $amountCents) {
                $lease->booking_deposit_paid = $amountCents / 100;
                $lease->save();
            });

            $this->audit->log(
                eventType:      'booking_fee.held',
                sourceDatabase: 'ah_billing',
                tableName:      'booking_deposits',
                recordId:       $deposit->id,
                userId:         $deposit->payer_user_id,
                actionSummary:  'Booking fee paid and held — applicant won the spot; 7-day completion window opened',
                newValues:      ['amount_cents' => $amountCents, 'status' => 'held'],
            );

            $this->invalidate("lease_detail:{$lease->id}");
        } else {
            // Lost the first-to-pay race — refund the held fee.
            $this->refundLostRace($deposit);
        }

        return $deposit->refresh();
    }

    /**
     * Release the held booking fee to the landowner (minus the platform fee) once the
     * lease activates. Best-effort: a transfer failure (landowner not onboarded) still
     * records the resolution so the lifecycle isn't blocked. No-op when there is no
     * held fee for the lease.
     */
    public function disburseForLease(string $leaseId): void
    {
        $deposit = BookingDeposit::where('lease_id', $leaseId)->where('status', 'held')->first();
        if (! $deposit) {
            return;
        }

        $this->routeToLandowner($deposit, 'disbursed');
    }

    /**
     * Forfeit the held booking fee to the landowner (minus the platform fee) when the
     * 7-day completion window lapses — the listing was off-market while the winner sat
     * on it. No-op when there is no held fee for the lease.
     */
    public function forfeitForLease(string $leaseId): void
    {
        $deposit = BookingDeposit::where('lease_id', $leaseId)->where('status', 'held')->first();
        if (! $deposit) {
            return;
        }

        $this->routeToLandowner($deposit, 'forfeited');
    }

    // ── Internal ─────────────────────────────────────────────────────────────────

    /**
     * Transfer the held fee's net to the landowner's connected account and stamp the
     * final status. Disbursement and forfeiture both pay the landowner — they differ
     * only in why. Best-effort transfer: if the landowner can't yet receive it, the
     * status is still recorded (funds stay on the platform, flagged) so the lease
     * lifecycle is never blocked.
     */
    private function routeToLandowner(BookingDeposit $deposit, string $finalStatus): void
    {
        $landowner = $deposit->payee_user_id
            ? ConnectionRole::asSystem(fn () => User::on('identity')->find($deposit->payee_user_id))
            : null;

        $account = $landowner ? ConnectionRole::asSystem(fn () => $this->payouts->connectAccount($landowner)) : null;
        $quote   = $landowner
            ? ConnectionRole::asSystem(fn () => $this->payouts->quote($landowner, $deposit->amount_cents))
            : ['fee_cents' => 0, 'net_cents' => $deposit->amount_cents];

        $netCents   = (int) $quote['net_cents'];
        $transferId = null;
        if ($account && $account->charges_enabled && $netCents > 0) {
            $transfer = rescue(fn () => $this->stripe->createTransfer(
                $netCents,
                $account->stripe_account_id,
                ['purpose' => "booking_fee_{$finalStatus}", 'booking_deposit_id' => $deposit->id],
            ));
            $transferId = $transfer?->id;
        }

        $deposit->status                = $finalStatus;
        $deposit->application_fee_cents = (int) $quote['fee_cents'];
        $deposit->net_cents             = $netCents;
        $deposit->stripe_account_id     = $account?->stripe_account_id;
        $deposit->stripe_transfer_id    = $transferId;
        if ($finalStatus === 'disbursed') {
            $deposit->disbursed_at = now();
        }
        if ($finalStatus === 'forfeited') {
            $deposit->forfeited_at = now();
        }
        $deposit->save();

        $this->audit->log(
            eventType:      "booking_fee.{$finalStatus}",
            sourceDatabase: 'ah_billing',
            tableName:      'booking_deposits',
            recordId:       $deposit->id,
            userId:         $deposit->payee_user_id,
            actionSummary:  $finalStatus === 'disbursed'
                ? 'Booking fee released to landowner — lease completed'
                : 'Booking fee forfeited to landowner — 7-day completion window lapsed',
            newValues:      ['status' => $finalStatus, 'transfer_made' => $transferId !== null],
        );

        if ($deposit->lease_id) {
            $this->invalidate("lease_detail:{$deposit->lease_id}");
        }
    }

    /** Refund a held fee whose payer lost the reservation race, and flip the row. */
    private function refundLostRace(BookingDeposit $deposit): void
    {
        rescue(fn () => $this->stripe->refundPaymentIntent(
            $deposit->stripe_payment_intent_id,
            null,
            'Booking fee refunded — another applicant booked this listing first',
        ));

        $deposit->status      = 'refunded';
        $deposit->refunded_at = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'booking_fee.refunded',
            sourceDatabase: 'ah_billing',
            tableName:      'booking_deposits',
            recordId:       $deposit->id,
            userId:         $deposit->payer_user_id,
            actionSummary:  'Booking fee refunded — applicant lost the first-to-pay race',
            newValues:      ['status' => 'refunded'],
        );
    }

    /** The landowner (property owner) for an application's listing, via the property DB. */
    private function resolveLandownerId(LeaseApplication $application): ?string
    {
        $propertyId = $application->property_id_snapshot
            ?? DB::connection('property')
                ->table('property_listings')
                ->where('id', $application->listing_id)
                ->value('property_id');

        if (! $propertyId) {
            return null;
        }

        return DB::connection('property')
            ->table('properties')
            ->where('id', $propertyId)
            ->value('owner_user_id');
    }
}
