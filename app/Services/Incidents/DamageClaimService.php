<?php

namespace App\Services\Incidents;

use App\Models\Identity\User;
use App\Models\Incidents\DamageClaim;
use App\Models\Lease\Lease;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use App\Services\Billing\SecurityDepositService;
use App\Services\Documents\DocumentService;

/**
 * Damage claims (DB 10) — a landowner's itemized claim for property/equipment
 * damage, with an admin review workflow. An approved claim can optionally drive a
 * deposit forfeiture-claim (SecurityDepositService::forfeit), which then follows the
 * contest/adjudication loop. All cross-DB data is assembled in the service layer.
 */
class DamageClaimService extends BaseService
{
    /** Review decisions for review(). */
    public const DECISION_APPROVE = 'approved';
    public const DECISION_DENY    = 'denied';
    public const DECISION_PAID    = 'paid';
    public const DECISION_COVERED = 'covered'; // settled by insurance

    public function __construct(
        private readonly DocumentService        $documents,
        private readonly SecurityDepositService $deposits,
        private readonly AuditService           $audit,
    ) {}

    /** A lease's damage claims, newest first (for member-portal display). */
    public function forLease(string $leaseId): \Illuminate\Support\Collection
    {
        return DamageClaim::where('lease_id', $leaseId)
            ->latest('created_at')
            ->get();
    }

    /**
     * File a landowner's damage claim with photo evidence and optional insurance.
     *
     * @param array<int,string>   $evidenceDocIds DB 11 document ids
     * @param array<string,mixed> $insurance      Optional: covered_party, insurer_name, policy_number, coi_document_id
     */
    public function file(
        Lease $lease,
        User $claimant,
        string $claimType,
        int $amountClaimedCents,
        string $description,
        array $evidenceDocIds = [],
        array $insurance = [],
    ): DamageClaim {
        if ($amountClaimedCents <= 0) {
            throw new \InvalidArgumentException('Claimed amount must be greater than zero.');
        }

        $evidenceDocIds = array_values(array_filter($evidenceDocIds));

        $claim = DamageClaim::create([
            'lease_id'                => $lease->id,
            'claimant_user_id'        => $claimant->id,
            'claim_type'              => $claimType,
            'status'                  => 'submitted',
            'description'             => $description,
            'amount_claimed_cents'    => $amountClaimedCents,
            'evidence_document_ids'   => $evidenceDocIds,
            'insurance_covered_party' => $insurance['covered_party'] ?? null,
            'insurer_name'            => $insurance['insurer_name'] ?? null,
            'policy_number'           => $insurance['policy_number'] ?? null,
            'coi_document_id'         => $insurance['coi_document_id'] ?? null,
            'coverage_status'         => isset($insurance['covered_party']) && $insurance['covered_party'] !== 'none'
                ? 'claimed' : null,
        ]);

        if ($evidenceDocIds) {
            $this->documents->attachDocuments($evidenceDocIds);
        }

        $this->audit->log(
            eventType:      'damage_claim.filed',
            sourceDatabase: 'ah_incidents',
            tableName:      'damage_claims',
            recordId:       $claim->id,
            userId:         $claimant->id,
            actionSummary:  'Landowner filed a damage claim',
            newValues:      ['claim_type' => $claimType, 'amount_claimed_cents' => $amountClaimedCents],
        );

        return $claim;
    }

    /**
     * Record an admin review decision: approve (with an approved amount), deny, mark
     * paid, or mark covered by insurance.
     *
     * @throws \InvalidArgumentException on an unknown decision or a missing approved amount
     */
    public function review(
        string $claimId,
        string $decision,
        ?int $amountApprovedCents = null,
        ?string $actorUserId = null,
        ?string $note = null,
    ): DamageClaim {
        $claim = DamageClaim::findOrFail($claimId);

        if (! in_array($decision, [self::DECISION_APPROVE, self::DECISION_DENY, self::DECISION_PAID, self::DECISION_COVERED], true)) {
            throw new \InvalidArgumentException("Unknown damage-claim decision: {$decision}.");
        }

        if ($decision === self::DECISION_APPROVE) {
            if ($amountApprovedCents === null || $amountApprovedCents <= 0 || $amountApprovedCents > (int) $claim->amount_claimed_cents) {
                throw new \InvalidArgumentException('Approved amount must be between 1 and the claimed amount.');
            }
            $claim->amount_approved_cents = $amountApprovedCents;
        }

        if ($decision === self::DECISION_COVERED) {
            $claim->coverage_status = 'covered';
        }

        $claim->status              = $decision;
        $claim->reviewed_by_user_id = $actorUserId;
        $claim->review_notes        = $note;
        $claim->resolved_at         = in_array($decision, [self::DECISION_DENY, self::DECISION_PAID, self::DECISION_COVERED], true)
            ? now() : $claim->resolved_at;
        $claim->save();

        $this->audit->log(
            eventType:      'damage_claim.reviewed',
            sourceDatabase: 'ah_incidents',
            tableName:      'damage_claims',
            recordId:       $claim->id,
            userId:         $actorUserId,
            actionSummary:  "Damage claim {$decision}",
            newValues:      ['status' => $decision, 'amount_approved_cents' => $claim->amount_approved_cents, 'note' => $note],
        );

        return $claim;
    }

    /**
     * Settle an approved claim from the lease's held deposit by recording a
     * forfeiture-claim for the approved amount (fault = lessee). The forfeiture then
     * follows the contest/adjudication loop. Links the deposit back onto the claim
     * and marks it paid.
     *
     * @throws \RuntimeException when the claim isn't approved or has no held deposit
     */
    public function forfeitDepositForApproved(string $claimId, ?string $actorUserId = null): DamageClaim
    {
        $claim = DamageClaim::findOrFail($claimId);
        if ($claim->status !== self::DECISION_APPROVE || ! $claim->amount_approved_cents) {
            throw new \RuntimeException("Damage claim {$claimId} is not approved with an amount.");
        }

        $deposit = $this->deposits->forLease($claim->lease_id);
        if (! $deposit || $deposit->status !== 'held') {
            throw new \RuntimeException("Lease {$claim->lease_id} has no held deposit to forfeit.");
        }

        $category = $claim->claim_type === 'equipment_damage' ? 'equipment_damage' : 'property_damage';
        $amount   = min((int) $claim->amount_approved_cents, $deposit->remainingCents());

        $this->deposits->forfeit(
            $deposit->id,
            $amount,
            "Damage claim {$claim->id}: {$claim->description}",
            $actorUserId,
            SecurityDepositService::FAULT_LESSEE,
            $category,
        );

        $claim->security_deposit_id = $deposit->id;
        $claim->status              = self::DECISION_PAID;
        $claim->resolved_at         = now();
        $claim->save();

        $this->audit->log(
            eventType:      'damage_claim.deposit_forfeited',
            sourceDatabase: 'ah_incidents',
            tableName:      'damage_claims',
            recordId:       $claim->id,
            userId:         $actorUserId,
            actionSummary:  'Approved damage claim settled by forfeiting the security deposit',
            newValues:      ['security_deposit_id' => $deposit->id, 'amount_cents' => $amount],
        );

        return $claim;
    }
}
