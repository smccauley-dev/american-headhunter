<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;

class Trophy extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'trophies';

    protected $fillable = [
        'harvest_log_id',
        'scoring_system',
        'gross_score',
        'net_score',
        'is_official',
        'scored_by',
        'scored_at',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'gross_score' => 'decimal:2',
            'net_score' => 'decimal:2',
            'is_official' => 'boolean',
            'scored_at' => 'datetime',
        ]);
    }
}
