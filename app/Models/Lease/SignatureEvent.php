<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Permanent legal record — never soft-deleted or hard-deleted
class SignatureEvent extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'signature_events';

    protected $fillable = [
        'lease_id',
        'user_id',
        'provider',
        'provider_signature_id',
        'event_type',
        'occurred_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function delete(): bool|null
    {
        throw new \LogicException('SignatureEvent records are permanent legal records and cannot be deleted.');
    }

    public function forceDelete(): bool|null
    {
        throw new \LogicException('SignatureEvent records are permanent legal records and cannot be deleted.');
    }
}
