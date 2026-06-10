<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only — no updated_at, no deleted_at
class LeaseRenewal extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'lease_renewals';

    protected $fillable = [
        'lease_id',
        'offered_at',
        'offer_expires_at',
        'new_start',
        'new_end',
        'new_price',
        'status',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'offered_at'       => 'datetime',
            'offer_expires_at' => 'datetime',
            'new_start'        => 'date',
            'new_end'          => 'date',
            'new_price'        => 'decimal:2',
            'responded_at'     => 'datetime',
            'created_at'       => 'datetime',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->offer_expires_at?->isPast();
    }
}
