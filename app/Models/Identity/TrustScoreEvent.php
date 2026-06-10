<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustScoreEvent extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'trust_score_events';

    protected $fillable = [
        'user_id',
        'event_type',
        'delta',
        'score_after',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'delta'      => 'integer',
            'score_after' => 'integer',
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
