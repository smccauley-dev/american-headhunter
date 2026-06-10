<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaConfiguration extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'mfa_configurations';

    // Never include secret_encrypted in fillable — written only via TotpMfaMethod::storeSecret()
    protected $fillable = ['user_id', 'method', 'is_enabled', 'verified_at'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_enabled'  => 'boolean',
            'verified_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
