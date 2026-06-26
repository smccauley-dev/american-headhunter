<?php

namespace App\Services\Billing;

use App\Models\Billing\SecurityDeposit;
use App\Models\Identity\User;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Identity\TrustScoreService;
use App\Services\Property\PropertyService;
use Illuminate\Support\Facades\DB;
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
        private readonly StripeService     $stripe,
        private readonly PropertyService   $properties,
        private readonly AuditService      $audit,
        private readonly PayoutService     $payouts,
        private readonly TrustScoreService $trustScores,
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
        if ($deposit->forfeit_trust_status === 'pending') {
            throw new \RuntimeException("Security deposit {$depositId} has a pending forfeiture claim — resolve it before releasing.");
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

    /** Forfeit fault attributions and the per-fault settlement policy. */
    public const FAULT_LESSEE              = 'lessee';              // hunter caused it — deferred claim, provisional Trust hit
    public const FAULT_LANDOWNER_INITIATED = 'landowner_initiated'; // no-fault / landowner's call — settles immediately, no penalty
    public const FAULT_CONTESTED           = 'contested';           // hunter disputes — held pending an admin decision

    /** Days a hunter has to contest a forfeiture-claim before it auto-finalizes as upheld. */
    public const CONTEST_WINDOW_DAYS = 7;

    /**
     * Forfeit some or all of a held deposit. WHAT happens depends on $fault:
     *
     *  - LESSEE / CONTESTED (a hunter-fault claim): records a CLAIM only — the
     *    intended amount, reason ($category) + note, and any insurance — and parks
     *    forfeit_trust_status='pending' with a contest deadline. **No money moves**
     *    and the deposit stays 'held'. The hunter can contest (DisputeService) or,
     *    if insured, opt out; settlement + Trust Score both defer to that terminal
     *    outcome (confirm/waive/opt-out), or to autoFinalizePastDeadline().
     *
     *  - LANDOWNER_INITIATED (no-fault, uncontestable — e.g. an agreed cleaning
     *    deduction): settles immediately (keep) and disburses to the landowner; no
     *    contest window, no Trust Score penalty (forfeit_trust_status stays null).
     *
     * One forfeiture per deposit — guarded on forfeit_fault being unset.
     *
     * @param array<string,mixed> $insurance Optional: covered_party, insurer_name, policy_number, coi_document_id
     *
     * @throws \RuntimeException         when the deposit is not held or already has a forfeiture claim
     * @throws \InvalidArgumentException when the amount is outside the remaining balance or $fault is invalid
     */
    public function forfeit(
        string $depositId,
        int $amountCents,
        string $reason,
        ?string $actorUserId = null,
        string $fault = self::FAULT_LESSEE,
        ?string $category = null,
        array $insurance = [],
    ): SecurityDeposit {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->status !== 'held') {
            throw new \RuntimeException("Security deposit {$depositId} is not held (status {$deposit->status}).");
        }
        if ($deposit->forfeit_fault !== null) {
            throw new \RuntimeException("Security deposit {$depositId} already has a forfeiture claim.");
        }

        $remaining = $deposit->remainingCents();
        if ($amountCents <= 0 || $amountCents > $remaining) {
            throw new \InvalidArgumentException("Forfeit amount must be between 1 and {$remaining} cents.");
        }

        if (! in_array($fault, [self::FAULT_LESSEE, self::FAULT_LANDOWNER_INITIATED, self::FAULT_CONTESTED], true)) {
            throw new \InvalidArgumentException("Invalid forfeit fault: {$fault}.");
        }

        $deposit->forfeited_amount_cents = $amountCents; // intended claim
        $deposit->forfeit_reason         = $reason;
        $deposit->forfeit_category       = $category;
        $deposit->forfeit_fault          = $fault;
        $deposit->forfeit_initiated_by   = $actorUserId;
        $this->applyInsurance($deposit, $insurance);

        // A no-fault forfeiture is uncontestable: settle it now, no penalty.
        if ($fault === self::FAULT_LANDOWNER_INITIATED) {
            $payoutId = $this->settleForfeiture($deposit, 'keep');
            $deposit->forfeit_trust_status = null;
            $deposit->save();

            $this->audit->log(
                eventType:      'security_deposit.forfeited',
                sourceDatabase: 'ah_billing',
                tableName:      'security_deposits',
                recordId:       $deposit->id,
                userId:         $actorUserId,
                actionSummary:  $payoutId
                    ? 'No-fault deposit forfeiture settled and disbursed to landowner via Connect'
                    : 'No-fault deposit forfeiture settled (landowner payout pending Connect onboarding)',
                newValues:      [
                    'status'                 => $deposit->status,
                    'forfeited_amount_cents' => (int) $deposit->forfeited_amount_cents,
                    'forfeit_fault'          => $fault,
                    'payout_id'              => $payoutId,
                ],
            );

            $this->invalidate("lease_detail:{$deposit->lease_id}");

            return $deposit;
        }

        // A hunter-fault claim: defer money + Trust Score to the terminal outcome.
        $deposit->forfeit_trust_status     = 'pending';
        $deposit->forfeit_contest_deadline = now()->addDays(self::CONTEST_WINDOW_DAYS);
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.forfeit_claimed',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Security deposit forfeiture claimed — held pending contest/finalize',
            newValues:      [
                'status'                   => $deposit->status,
                'forfeited_amount_cents'   => (int) $deposit->forfeited_amount_cents,
                'forfeit_reason'           => $reason,
                'forfeit_category'         => $category,
                'forfeit_fault'            => $fault,
                'forfeit_trust_status'     => 'pending',
                'forfeit_contest_deadline' => $deposit->forfeit_contest_deadline?->toIso8601String(),
            ],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * The single place a forfeiture-claim's money moves. Mutates the deposit (the
     * caller persists). Returns the landowner payout id on a 'keep', else null.
     *
     *  - keep:   disburse the claimed amount to the landowner, refund any remainder
     *            to the hunter (status forfeited / partially_released).
     *  - refund: the claim is void — return everything to the hunter, zero the
     *            forfeited amount (status released).
     */
    private function settleForfeiture(SecurityDeposit $deposit, string $disposition): ?string
    {
        if (! in_array($disposition, ['keep', 'refund'], true)) {
            throw new \InvalidArgumentException("Invalid forfeiture disposition: {$disposition}.");
        }

        // Nothing has moved yet, so the whole un-refunded balance is still captured.
        $captured = (int) $deposit->amount_cents - (int) $deposit->refunded_amount_cents;

        if ($disposition === 'refund') {
            if ($captured > 0 && $deposit->stripe_payment_intent_id) {
                $refund = $this->stripe->refundPaymentIntent(
                    $deposit->stripe_payment_intent_id,
                    $captured,
                    'Security deposit returned — forfeiture overturned',
                );
                $deposit->stripe_refund_id      = $refund->id;
                $deposit->refunded_amount_cents = (int) $deposit->refunded_amount_cents + $captured;
            }
            $deposit->forfeited_amount_cents = 0; // claim void
            $deposit->released_at            = now();
            $deposit->status                 = 'released';

            return null;
        }

        // keep: refund any remainder beyond the claimed amount, disburse the rest.
        $claimed     = (int) $deposit->forfeited_amount_cents;
        $returnCents = $captured - $claimed;
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

        return $this->disburseForfeitedAmount($deposit, $claimed);
    }

    /** Merge provided insurance details onto the deposit; marks coverage 'claimed' when a covered party is named. */
    private function applyInsurance(SecurityDeposit $deposit, array $insurance): void
    {
        if (! $insurance) {
            return;
        }

        $coveredParty = $insurance['covered_party'] ?? null;
        if ($coveredParty !== null) {
            $deposit->insurance_covered_party = $coveredParty;
            $deposit->coverage_status = $coveredParty === 'none' ? 'none' : 'claimed';
        }
        foreach (['insurer_name', 'policy_number', 'coi_document_id'] as $field) {
            if (array_key_exists($field, $insurance)) {
                $deposit->{$field} = $insurance[$field];
            }
        }
    }

    /** Whether either party has insurance coverage on file for this deposit. */
    private function hasInsuranceCoverage(SecurityDeposit $deposit): bool
    {
        return $deposit->insurance_covered_party !== null
            && $deposit->insurance_covered_party !== 'none';
    }

    /**
     * UPHOLD a pending forfeiture-claim: the landowner was right. Settles the money
     * to the landowner (keep), APPLIES the hunter's −10 Trust Score penalty, and
     * marks forfeit_trust_status='applied'. Only acts on a 'pending' claim.
     */
    public function confirmForfeitFault(string $depositId, ?string $actorUserId = null): SecurityDeposit
    {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->forfeit_trust_status !== 'pending') {
            throw new \RuntimeException("Security deposit {$depositId} has no pending forfeiture decision.");
        }

        $payoutId = $this->settleForfeiture($deposit, 'keep');

        $hunter = User::on('identity')->find($deposit->payer_user_id);
        if ($hunter) {
            $this->trustScores->record($hunter, 'deposit_forfeited_against_user', [
                'security_deposit_id' => $deposit->id,
                'lease_id'            => $deposit->lease_id,
                'forfeit_category'    => $deposit->forfeit_category,
            ]);
        }

        $deposit->forfeit_fault        = self::FAULT_LESSEE; // a confirmed fault is the hunter's, even if it was contested
        $deposit->forfeit_trust_status = 'applied';
        $deposit->forfeit_resolved_by  = $actorUserId;
        $deposit->forfeit_resolved_at  = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.forfeit_fault_confirmed',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Forfeiture upheld against the hunter; money settled to landowner and Trust Score penalty applied',
            newValues:      ['status' => $deposit->status, 'forfeit_trust_status' => 'applied', 'payout_id' => $payoutId],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * OVERTURN a pending forfeiture-claim: the landowner's forfeiture was not
     * justified. Refunds the FULL deposit to the hunter, marks the hunter's penalty
     * 'waived' (never applied). The landowner-side Trust penalty for an unjustified
     * forfeiture is applied by DisputeService (it owns the dispute outcome).
     */
    public function waiveForfeitFault(string $depositId, ?string $actorUserId = null, ?string $note = null): SecurityDeposit
    {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->forfeit_trust_status !== 'pending') {
            throw new \RuntimeException("Security deposit {$depositId} has no pending forfeiture decision.");
        }

        $this->settleForfeiture($deposit, 'refund');

        $deposit->forfeit_trust_status = 'waived';
        $deposit->forfeit_resolved_by  = $actorUserId;
        $deposit->forfeit_resolved_at  = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.forfeit_fault_waived',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Forfeiture overturned — deposit refunded to hunter, no fault penalty',
            newValues:      ['status' => $deposit->status, 'forfeit_trust_status' => 'waived', 'note' => $note],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * OPT OUT of the dispute system because a party carries insurance — the insurer
     * handles the loss, so there's no fault penalty for either side. Settles the
     * money per $disposition (keep|refund) and marks forfeit_trust_status='opted_out'.
     * Requires insurance coverage on file or provided in $insurance.
     *
     * @param array<string,mixed> $insurance Optional coverage to record before settling
     *
     * @throws \RuntimeException when the claim isn't pending or no insurance is on file
     */
    public function optOutForfeitDecision(
        string $depositId,
        string $disposition,
        ?string $actorUserId = null,
        ?string $note = null,
        array $insurance = [],
    ): SecurityDeposit {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->forfeit_trust_status !== 'pending') {
            throw new \RuntimeException("Security deposit {$depositId} has no pending forfeiture decision.");
        }

        $this->applyInsurance($deposit, $insurance);
        if (! $this->hasInsuranceCoverage($deposit)) {
            throw new \RuntimeException("Security deposit {$depositId} cannot be opted out without insurance coverage on file.");
        }

        $payoutId = $this->settleForfeiture($deposit, $disposition);

        $deposit->coverage_status      = 'covered';
        $deposit->forfeit_trust_status = 'opted_out';
        $deposit->forfeit_resolved_by  = $actorUserId;
        $deposit->forfeit_resolved_at  = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.forfeit_opted_out',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Forfeiture settled via insurance opt-out — no Trust Score change for either party',
            newValues:      [
                'status'                  => $deposit->status,
                'forfeit_trust_status'    => 'opted_out',
                'disposition'             => $disposition,
                'insurance_covered_party' => $deposit->insurance_covered_party,
                'payout_id'               => $payoutId,
                'note'                    => $note,
            ],
        );

        $this->invalidate("lease_detail:{$deposit->lease_id}");

        return $deposit;
    }

    /**
     * Admin override: REVERSE an already-applied hunter penalty (e.g. new evidence
     * exonerates them after finalize). Restores the +10 the confirmation deducted
     * via a 'deposit_forfeiture_reversed' event. Trust-only — any money already
     * disbursed is reconciled manually (flagged in the audit note). Only acts on an
     * 'applied' penalty.
     */
    public function reverseForfeitFault(string $depositId, ?string $actorUserId = null, ?string $note = null): SecurityDeposit
    {
        $deposit = SecurityDeposit::findOrFail($depositId);
        if ($deposit->forfeit_trust_status !== 'applied') {
            throw new \RuntimeException("Security deposit {$depositId} has no applied forfeiture penalty to reverse.");
        }

        $hunter = User::on('identity')->find($deposit->payer_user_id);
        if ($hunter) {
            $this->trustScores->record($hunter, 'deposit_forfeiture_reversed', [
                'security_deposit_id' => $deposit->id,
                'lease_id'            => $deposit->lease_id,
            ]);
        }

        $deposit->forfeit_trust_status = 'reversed';
        $deposit->forfeit_resolved_by  = $actorUserId;
        $deposit->forfeit_resolved_at  = now();
        $deposit->save();

        $this->audit->log(
            eventType:      'security_deposit.forfeit_fault_reversed',
            sourceDatabase: 'ah_billing',
            tableName:      'security_deposits',
            recordId:       $deposit->id,
            userId:         $actorUserId,
            actionSummary:  'Forfeiture penalty reversed; Trust Score restored — money clawback handled manually',
            newValues:      ['forfeit_trust_status' => 'reversed', 'note' => $note],
        );

        return $deposit;
    }

    /**
     * Sweep forfeiture-claims whose contest window has lapsed with no open dispute
     * and finalize them as upheld (the hunter never contested). Called from the
     * daily deposit job. Returns the number finalized.
     */
    public function autoFinalizePastDeadline(): int
    {
        $due = SecurityDeposit::where('forfeit_trust_status', 'pending')
            ->whereNotNull('forfeit_contest_deadline')
            ->where('forfeit_contest_deadline', '<=', now())
            ->get();

        $count = 0;
        foreach ($due as $deposit) {
            if ($this->hasOpenForfeitureDispute($deposit->id)) {
                continue; // a contest is in flight — wait for the admin's adjudication
            }
            try {
                $this->confirmForfeitFault($deposit->id);
                $count++;
            } catch (\Throwable $e) {
                // One deposit's finalize failure must not abort the sweep.
                Log::error('autoFinalizePastDeadline: finalize failed', [
                    'security_deposit_id' => $deposit->id,
                    'error'               => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /** Whether an unresolved dispute is contesting this deposit's forfeiture (cross-DB read, no join). */
    private function hasOpenForfeitureDispute(string $depositId): bool
    {
        return \App\Models\Incidents\LeaseDispute::where('security_deposit_id', $depositId)
            ->whereIn('status', ['open', 'mediation', 'arbitration', 'escalated'])
            ->whereNull('deleted_at')
            ->exists();
    }

    // ── Forfeiture oversight (report) ────────────────────────────────────────────

    /** A landowner is flagged for review at or above this forfeiture rate … */
    public const REVIEW_FLAG_RATE = 0.40;
    /** … but only once they have at least this many forfeitures (avoids n=1 noise). */
    public const REVIEW_MIN_FORFEITS = 3;

    /** Deposit statuses that represent a concluded outcome (the rate denominator). */
    private const RESOLVED_STATUSES = ['partially_released', 'released', 'forfeited', 'refunded'];

    /**
     * Per-landowner forfeiture stats for the oversight report. A landowner who
     * forfeits an abnormal share of their concluded deposits is flagged for an
     * admin to review — frequency is the scam tell, independent of stated reason.
     *
     * @return array<int,array{user_id:string,name:string,resolved:int,forfeits:int,rate:float,forfeited_cents:int,flagged:bool}>
     */
    public function landownerForfeitureStats(): array
    {
        $rows = DB::connection('billing')->table('security_deposits')
            ->selectRaw('payee_user_id')
            ->selectRaw('COUNT(*) AS resolved')
            ->selectRaw('COUNT(*) FILTER (WHERE forfeited_amount_cents > 0) AS forfeits')
            ->selectRaw('COALESCE(SUM(forfeited_amount_cents), 0) AS forfeited_cents')
            ->whereIn('status', self::RESOLVED_STATUSES)
            ->groupBy('payee_user_id')
            ->havingRaw('COUNT(*) FILTER (WHERE forfeited_amount_cents > 0) > 0')
            ->get();

        return $this->shapeForfeitureRows($rows, 'payee_user_id', flagRate: true);
    }

    /**
     * Per-hunter forfeiture stats — how often a hunter has had a deposit forfeited
     * against them, split by whether the Trust Score penalty is applied/pending/waived.
     *
     * @return array<int,array{user_id:string,name:string,resolved:int,forfeits:int,rate:float,forfeited_cents:int,flagged:bool}>
     */
    public function hunterForfeitureStats(): array
    {
        $rows = DB::connection('billing')->table('security_deposits')
            ->selectRaw('payer_user_id')
            ->selectRaw('COUNT(*) AS resolved')
            ->selectRaw('COUNT(*) FILTER (WHERE forfeited_amount_cents > 0) AS forfeits')
            ->selectRaw('COALESCE(SUM(forfeited_amount_cents), 0) AS forfeited_cents')
            ->whereIn('status', self::RESOLVED_STATUSES)
            ->groupBy('payer_user_id')
            ->havingRaw('COUNT(*) FILTER (WHERE forfeited_amount_cents > 0) > 0')
            ->get();

        // Hunters aren't "flagged" — that signal is the landowner's; we just rank them.
        return $this->shapeForfeitureRows($rows, 'payer_user_id', flagRate: false);
    }

    /**
     * Resolve the grouped rows: attach the user's display name (cross-DB, batched),
     * compute the forfeiture rate, set the review flag (landowners only), and sort
     * flagged/most-frequent first.
     */
    private function shapeForfeitureRows(\Illuminate\Support\Collection $rows, string $idKey, bool $flagRate): array
    {
        $ids   = $rows->pluck($idKey)->filter()->all();
        $names = User::on('identity')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->getFilamentName()]);

        $stats = $rows->map(function ($row) use ($idKey, $names, $flagRate): array {
            $resolved = (int) $row->resolved;
            $forfeits = (int) $row->forfeits;
            $rate     = $resolved > 0 ? $forfeits / $resolved : 0.0;
            $userId   = $row->{$idKey};

            return [
                'user_id'         => $userId,
                'name'            => $names[$userId] ?? 'Unknown user',
                'resolved'        => $resolved,
                'forfeits'        => $forfeits,
                'rate'            => $rate,
                'forfeited_cents' => (int) $row->forfeited_cents,
                'flagged'         => $flagRate
                    && $forfeits >= self::REVIEW_MIN_FORFEITS
                    && $rate >= self::REVIEW_FLAG_RATE,
            ];
        })->sortByDesc(fn (array $r) => [$r['flagged'] ? 1 : 0, $r['rate'], $r['forfeits']])
          ->values()
          ->all();

        return $stats;
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
