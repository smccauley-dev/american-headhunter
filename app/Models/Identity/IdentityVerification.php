<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'identity_verifications';

    protected $fillable = [
        'user_id',
        'provider',
        'verification_type',
        'status',
        'provider_session_id',
        'verified_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'verified_at' => 'datetime',
            'expires_at'  => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
