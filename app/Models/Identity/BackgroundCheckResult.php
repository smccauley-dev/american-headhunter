<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackgroundCheckResult extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'background_check_results';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_report_id',
        'status',
        'report_type',
        'initiated_at',
        'completed_at',
        'expires_at',
    ];

    protected $hidden = ['raw_result_encrypted'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at'   => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isClear(): bool
    {
        return $this->status === 'clear';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
