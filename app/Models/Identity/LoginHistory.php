<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'login_history';

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'success',
        'failure_reason',
        'mfa_used',
    ];

    protected function casts(): array
    {
        return [
            'success'    => 'boolean',
            'mfa_used'   => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
