<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

class CwdZone extends BaseModel
{
    protected $connection = 'wildlife';

    protected $table = 'cwd_zones';

    protected $fillable = [
        'state_code',
        'zone_name',
        'zone_type',
        'regulations',
        'effective_date',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'effective_date' => 'date',
        ]);
    }

    /** A positive zone can legally require sample submission on harvest. */
    public function requiresAcknowledgment(): bool
    {
        return $this->zone_type === 'positive';
    }
}
