<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuardianRelationship extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'guardian_relationships';

    protected $fillable = [
        'minor_user_id',
        'guardian_user_id',
        'consent_granted_at',
        'consent_expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'consent_granted_at' => 'datetime',
            'consent_expires_at' => 'datetime',
            'revoked_at'         => 'datetime',
        ]);
    }

    public function minor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'minor_user_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->consent_expires_at === null || $this->consent_expires_at->isFuture());
    }
}
