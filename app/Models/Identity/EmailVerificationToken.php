<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

class EmailVerificationToken extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'email_verification_tokens';

    protected $fillable = [
        'user_id',
        'email',
        'token_hash',
        'expires_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'verified_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }
}
