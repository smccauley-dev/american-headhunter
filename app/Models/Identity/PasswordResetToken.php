<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

class PasswordResetToken extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'password_reset_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
