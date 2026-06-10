<?php

namespace App\Models\Identity;

use Illuminate\Database\Eloquent\Model;

class UserRecoveryCode extends Model
{
    protected $connection = 'identity';
    protected $table      = 'user_recovery_codes';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    // code_hash must never appear in serialized output — it is bcrypt-hashed
    // but still an auth credential. Display recovery code counts only (never hashes).
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
