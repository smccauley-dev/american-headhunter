<?php

namespace App\Services\Incidents;

use App\Models\Identity\User;
use App\Models\Incidents\LeaseDispute;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Documents\DocumentService;
use App\Services\Identity\TrustScoreService;

/**
 * Lease disputes (DB 10) — the hunter's forfeiture-contest loop and its adjudication.
 *
 * A landowner's deposit forfeiture is only a CLAIM (money held, Trust Score pending).
 * A hunter contests it here with photo evidence; an admin adjudicates and the outcome
 * is finalized through SecurityDepositService — the only place money moves and Trust
 * Score changes. All cross-DB data (deposit, users, documents) is assembled in this
 * service, never via Eloquent relationships.
 */
class DisputeService extends BaseService
{
    /** Adjudication outcomes for resolve(). */
    public const OUTCOME_UPHOLD   = 'uphold';   // landowner was right — forfeiture stands, hunter penalized
    public const OUTCOME_OVERTURN = 'overturn'; // landowner was wrong — deposit refunded, landowner penalized
    public const OUTCOME_OPT_OUT  = 'opt_out';  // settled via insurance — no Trust Score for either party

    public function __construct(
        private readonly SecurityDepositService $deposits,
        private readonly TrustScoreService      $trustScores,
        private readonly DocumentService        $documents,
        private readonly AuditService           $audit,
    ) {}

    /**
     * File a hunter's contest of a deposit forfeiture. Derives the landowner
     * (respondent) and the contested deposit from the lease, guards that the deposit
     * is a pending hunter-fault forfeiture-claim not already disputed, attaches the
     * evidence photos, and opens the dispute.
     *
     * @param array<int,string> $evidenceDocIds DB 11 document ids (photo proof)
     *
     * @throws \RuntimeException when there is no contestable forfeiture or one already exists
     */
    public function fileForfeitureContest(
        Lease $lease,
        User $hunter,
        string $description,
        array $evidenceDocIds = [],
    ): LeaseDispute {
        $deposit = $this->deposits->forLease($lease->id);
        if (! $deposit || $deposit->forfeit_trust_status !== 'pending') {
            throw new \RuntimeException("Lease {$lease->id} has no pending forfeiture to contest.");
        }
        if ((string) $deposit->payer_user_id !== (string) $hunter->id) {
            throw new \RuntimeException('Only the deposit payer may contest its forfeiture.');
        }
        if ($this->openDisputeFor($deposit->id)) {
            throw new \RuntimeException("Deposit {$deposit->id} is already being contested.");
        }

        $evidenceDocIds = array_values(array_filter($evidenceDocIds));

        $dispute = LeaseDispute::create([
            'lease_id'              => $lease->id,
            'security_deposit_id'   => $deposit->id,
            'initiator_user_id'     => $hunter->id,
            'respondent_user_id'    => $deposit->payee_user_id,
            'dispute_type'          => 'damage',
            'status'                => 'open',
            'description'           => $description,
            'amount_disputed_cents' => (int) $deposit->forfeited_amount_cents,
            'evidence_document_ids' => $evidenceDocIds,
        ]);

        if ($evidenceDocIds) {
            $this->documents->attachDocuments($evidenceDocIds);
        }

        $this->audit->log(
            eventType:      'lease_dispute.filed',
            sourceDatabase: 'ah_incidents',
            tableName:      'lease_disputes',
            recordId:       $dispute->id,
            userId:         $hunter->id,
            actionSummary:  'Hunter contested a deposit forfeiture',
            newValues:      ['security_deposit_id' => $deposit->id, 'evidence_count' => count($evidenceDocIds)],
        );

        return $dispute;
    }

    /**
     * Adjudicate an open dispute. The outcome finalizes the contested deposit (the
     * only place money + Trust Score move) and closes the dispute:
     *
     *  - uphold:   SecurityDepositService::confirmForfeitFault (hunter −10, money to landowner).
     *  - overturn: SecurityDepositService::waiveForfeitFault (refund hunter) + landowner −10
     *              (dispute_resolved_against_user) and an optional hunter +5 (dispute_resolved_for_user).
     *  - opt_out:  SecurityDepositService::optOutForfeitDecision (keep|refund, no Trust Score).
     *
     * @param array<string,mixed> $opts opt_out requires ['disposition' => keep|refund]; insurance optional
     *
     * @throws \RuntimeException         when the dispute isn't open
     * @throws \InvalidArgumentException on an unknown outcome
     */
    public function resolve(
        string $disputeId,
        string $outcome,
        ?string $actorUserId = null,
        ?string $note = null,
        array $opts = [],
    ): LeaseDispute {
        $dispute = LeaseDispute::findOrFail($disputeId);
        if (! in_array($dispute->status, ['open', 'mediation', 'arbitration', 'escalated'], true)) {
            throw new \RuntimeException("Dispute {$disputeId} is not open (status {$dispute->status}).");
        }

        $depositId = $dispute->security_deposit_id;

        switch ($outcome) {
            case self::OUTCOME_UPHOLD:
                $this->deposits->confirmForfeitFault($depositId, $actorUserId);
                $resolution = 'Forfeiture upheld — landowner was within their rights.';
                break;

            case self::OUTCOME_OVERTURN:
                $this->deposits->waiveForfeitFault($depositId, $actorUserId, $note);

                // Bilateral: dock the landowner for an unjustified forfeiture, and
                // optionally restore standing to the vindicated hunter.
                $landowner = User::on('identity')->find($dispute->respondent_user_id);
                if ($landowner) {
                    $this->trustScores->record($landowner, 'dispute_resolved_against_user', [
                        'lease_dispute_id'    => $dispute->id,
                        'security_deposit_id' => $depositId,
                    ]);
                }
                if (! empty($opts['credit_initiator'])) {
                    $hunter = User::on('identity')->find($dispute->initiator_user_id);
                    if ($hunter) {
                        $this->trustScores->record($hunter, 'dispute_resolved_for_user', [
                            'lease_dispute_id' => $dispute->id,
                        ]);
                    }
                }
                $resolution = 'Forfeiture overturned — deposit refunded to the hunter.';
                break;

            case self::OUTCOME_OPT_OUT:
                $disposition = $opts['disposition'] ?? null;
                $this->deposits->optOutForfeitDecision(
                    $depositId,
                    (string) $disposition,
                    $actorUserId,
                    $note,
                    $opts['insurance'] ?? [],
                );
                $resolution = "Settled via insurance opt-out ({$disposition}) — no fault attributed.";
                break;

            default:
                throw new \InvalidArgumentException("Unknown dispute outcome: {$outcome}.");
        }

        $dispute->status      = 'resolved';
        $dispute->resolution  = $note ? "{$resolution} {$note}" : $resolution;
        $dispute->resolved_at = now();
        $dispute->save();

        $this->audit->log(
            eventType:      'lease_dispute.resolved',
            sourceDatabase: 'ah_incidents',
            tableName:      'lease_disputes',
            recordId:       $dispute->id,
            userId:         $actorUserId,
            actionSummary:  "Dispute resolved ({$outcome})",
            newValues:      ['outcome' => $outcome, 'security_deposit_id' => $depositId],
        );

        return $dispute;
    }

    /** The open dispute contesting a deposit, if any. */
    public function openDisputeFor(string $depositId): ?LeaseDispute
    {
        return LeaseDispute::where('security_deposit_id', $depositId)
            ->whereIn('status', ['open', 'mediation', 'arbitration', 'escalated'])
            ->first();
    }

    /** The most recent dispute on a deposit (open or resolved), for member-portal display. */
    public function latestForDeposit(string $depositId): ?LeaseDispute
    {
        return LeaseDispute::where('security_deposit_id', $depositId)
            ->latest('created_at')
            ->first();
    }
}
