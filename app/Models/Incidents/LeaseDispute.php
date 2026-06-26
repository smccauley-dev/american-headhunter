<?php

namespace App\Models\Incidents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lease dispute (DB 10) — the hunter's formal contest of a deposit forfeiture.
 *
 * System-authored: written only by the trusted ah_system path (the db.system
 * member route that files a contest, and the Filament admin panel that adjudicates);
 * read under ah_runtime scoped by RLS to the two parties (initiator/respondent) and
 * staff. All cross-DB references (lease, deposit, users, evidence documents) are
 * bare UUID columns resolved in the service layer — never Eloquent relationships.
 */
class LeaseDispute extends BaseModel
{
    use SoftDeletes;

    protected $connection = 'incidents';
    protected $table      = 'lease_disputes';

    protected $fillable = [
        'lease_id',
        'security_deposit_id',
        'initiator_user_id',
        'respondent_user_id',
        'dispute_type',
        'status',
        'description',
        'amount_disputed_cents',
        'evidence_document_ids',
        'resolution',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_disputed_cents' => 'integer',
            'evidence_document_ids' => 'array',
            'resolved_at'           => 'datetime',
            'deleted_at'            => 'datetime',
        ]);
    }

    /** Whether this dispute can still be adjudicated. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'mediation', 'arbitration', 'escalated'], true);
    }

    /** The hunter who filed the contest. Cross-DB (DB 1) — service layer, not Eloquent. */
    public function getInitiator(): ?\App\Models\Identity\User
    {
        return $this->initiator_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->initiator_user_id)
            : null;
    }

    /** The landowner whose forfeiture is contested. Cross-DB (DB 1). */
    public function getRespondent(): ?\App\Models\Identity\User
    {
        return $this->respondent_user_id
            ? app(\App\Services\Identity\UserService::class)->findById($this->respondent_user_id)
            : null;
    }

    /** The contested deposit. Cross-DB (DB 4) — direct model load, not an Eloquent relationship. */
    public function getDeposit(): ?\App\Models\Billing\SecurityDeposit
    {
        return $this->security_deposit_id
            ? \App\Models\Billing\SecurityDeposit::find($this->security_deposit_id)
            : null;
    }

    /** A human-readable label for the disputed lease. Cross-DB (DB 3). */
    public function leaseLabel(): ?string
    {
        if (! $this->lease_id) {
            return null;
        }

        $lease = app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);

        return $lease?->getProperty()?->title ?? ($lease ? 'Lease' : null);
    }
}
