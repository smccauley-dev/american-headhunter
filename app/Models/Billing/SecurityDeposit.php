<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * Refundable lease security deposit (DB 4). System-authored: written only by the
 * trusted ah_system path (deposit-charge webhook, release/forfeit jobs, Filament
 * admin); read under ah_runtime scoped by RLS to the two parties (lessee +
 * landowner) and staff. A separate captured charge funds the hold; release issues
 * a Stripe refund and forfeiture disburses to the landowner via a payout.
 *
 * No soft deletes — a deposit is a financial record that resolves via $status.
 */
class SecurityDeposit extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'security_deposits';

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'payment_id',
        'amount_cents',
        'refunded_amount_cents',
        'forfeited_amount_cents',
        'currency',
        'status',
        'forfeit_reason',
        'forfeit_category',
        'forfeit_fault',
        'forfeit_initiated_by',
        'forfeit_trust_status',
        'forfeit_resolved_by',
        'forfeit_resolved_at',
        'forfeit_contest_deadline',
        'insurance_covered_party',
        'insurer_name',
        'policy_number',
        'coi_document_id',
        'coverage_status',
        'stripe_payment_intent_id',
        'stripe_refund_id',
        'release_fee_cents',
        'release_fee_status',
        'release_fee_transfer_id',
        'held_at',
        'released_at',
    ];

    // Stripe identifiers never reach the client and must never be logged.
    protected $hidden = [
        'stripe_payment_intent_id',
        'stripe_refund_id',
        'release_fee_transfer_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents'           => 'integer',
            'refunded_amount_cents'  => 'integer',
            'forfeited_amount_cents' => 'integer',
            'release_fee_cents'      => 'integer',
            'held_at'                  => 'datetime',
            'released_at'              => 'datetime',
            'forfeit_resolved_at'      => 'datetime',
            'forfeit_contest_deadline' => 'datetime',
        ]);
    }

    /** A forfeiture-claim awaiting its terminal outcome (admin adjudication, opt-out, or auto-finalize). */
    public function hasPendingTrustDecision(): bool
    {
        return $this->forfeit_trust_status === 'pending';
    }

    /** Whether either party has insurance coverage on file for this deposit. */
    public function hasInsuranceCoverage(): bool
    {
        return $this->insurance_covered_party !== null
            && $this->insurance_covered_party !== 'none';
    }

    /** Cents still held — not yet refunded or forfeited. */
    public function remainingCents(): int
    {
        return (int) $this->amount_cents
            - (int) $this->refunded_amount_cents
            - (int) $this->forfeited_amount_cents;
    }

    /** Whether the deposit has been fully resolved (released, refunded, or forfeited). */
    public function isResolved(): bool
    {
        return in_array($this->status, ['released', 'refunded', 'forfeited'], true);
    }

    /**
     * The lease this deposit secures. Cross-DB (DB 3) — resolved via the service
     * layer, never an Eloquent relationship.
     */
    public function getLease(): ?\App\Models\Lease\Lease
    {
        if (! $this->lease_id) {
            return null;
        }

        return app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);
    }

    /**
     * The lessee who funded the deposit. Cross-DB (DB 1) — resolved via the
     * service layer, never an Eloquent relationship.
     */
    public function getPayer(): ?\App\Models\Identity\User
    {
        if (! $this->payer_user_id) {
            return null;
        }

        return app(\App\Services\Identity\UserService::class)->findById($this->payer_user_id);
    }

    /**
     * The landowner the deposit is held for. Cross-DB (DB 1) — resolved via the
     * service layer, never an Eloquent relationship.
     */
    public function getPayee(): ?\App\Models\Identity\User
    {
        if (! $this->payee_user_id) {
            return null;
        }

        return app(\App\Services\Identity\UserService::class)->findById($this->payee_user_id);
    }

    /**
     * A human-readable label for the secured lease — "Property Title · start–end".
     * Falls back gracefully when the cross-DB lease or property can't be resolved.
     */
    public function leaseLabel(): ?string
    {
        $lease = $this->getLease();

        if (! $lease) {
            return null;
        }

        $title = $lease->getProperty()?->title ?? 'Lease';
        $start = $lease->start_date?->format('M j, Y');
        $end   = $lease->end_date?->format('M j, Y');

        $dates = $start && $end ? " · {$start} – {$end}" : '';

        return $title.$dates;
    }
}
