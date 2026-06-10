<?php

namespace App\Models\Identity;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends BaseModelWithSoftDeletes
{
    protected $connection = 'identity';
    protected $table      = 'api_keys';

    protected $fillable = [
        'user_id',
        'name',
        'key_hash',
        'key_prefix',
        'scopes',
        'expires_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'scopes'       => 'array',
            'last_used_at' => 'datetime',
            'expires_at'   => 'datetime',
            'revoked_at'   => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->deleted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
