<?php

namespace App\Models\Incidents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Damage claim (DB 10) — a landowner's itemized claim for property/equipment damage.
 *
 * System-authored: written only by the trusted ah_system path (the db.system member
 * route that files a claim, and the Filament admin panel that reviews it); read under
 * ah_runtime scoped by RLS to the claimant and staff. All cross-DB references (lease,
 * deposit, users, evidence/COI documents) are bare UUID columns resolved in the
 * service layer — never Eloquent relationships.
 */
class DamageClaim extends BaseModel
{
    use SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'damage_claims';

    protected $fillable = [
        'lease_id',
        'security_deposit_id',
        'claimant_user_id',
        'claim_type',
        'status',
        'description',
        'amount_claimed_cents',
        'amount_approved_cents',
        'evidence_document_ids',
        'insurance_covered_party',
        'insurer_name',
        'policy_number',
        'coi_document_id',
        'coverage_status',
        'reviewed_by_user_id',
        'review_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_claimed_cents'  => 'integer',
            'amount_approved_cents' => 'integer',
            'evidence_document_ids' => 'array',
            'resolved_at'           => 'datetime',
            'deleted_at'            => 'datetime',
        ]);
    }

    /** The landowner who filed the claim. Cross-DB (DB 1) — service layer, not Eloquent. */
    public function getClaimant(): ?\App\Models\Identity\User
    {
        return $this->claimant_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->claimant_user_id)
            : null;
    }

    /** The held deposit this claim can settle against, if any. Cross-DB (DB 4). */
    public function getDeposit(): ?\App\Models\Billing\SecurityDeposit
    {
        return $this->security_deposit_id
            ? \App\Models\Billing\SecurityDeposit::find($this->security_deposit_id)
            : null;
    }

    /** A human-readable label for the claimed lease. Cross-DB (DB 3). */
    public function leaseLabel(): ?string
    {
        if (! $this->lease_id) {
            return null;
        }

        $lease = app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);

        return $lease?->getProperty()?->title ?? ($lease ? 'Lease' : null);
    }
}
