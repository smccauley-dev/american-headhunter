<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentLog extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'consent_log';

    protected $fillable = [
        'user_id',
        'consent_type',
        'granted',
        'version',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'granted'    => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
