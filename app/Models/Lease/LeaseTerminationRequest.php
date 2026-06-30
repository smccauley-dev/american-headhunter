<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hunter-initiated early-termination request the landowner approves or denies.
 * On approval the lease is terminated and the security deposit is forfeited as a
 * non-contestable early-exit penalty (kept by the landowner). System-authored —
 * written only through LeaseService under the db.system role.
 */
class LeaseTerminationRequest extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'lease_termination_requests';

    protected $fillable = [
        'lease_id',
        'requested_by_user_id',
        'reason',
        'status',
        'decided_by_user_id',
        'decision_note',
        'decided_at',
        'deposit_refunded_cents',
        'rent_refunded_cents',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'decided_at'             => 'datetime',
            'deposit_refunded_cents' => 'integer',
            'rent_refunded_cents'    => 'integer',
        ]);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }
}
