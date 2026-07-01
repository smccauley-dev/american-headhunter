<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

class HarvestQuota extends BaseModel
{
    protected $connection = 'wildlife';

    protected $table = 'harvest_quotas';

    protected $fillable = [
        'property_id',
        'lease_id',
        'species_code',
        'season_year',
        'max_harvest',
        'current_harvest',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'season_year' => 'integer',
            'max_harvest' => 'integer',
            'current_harvest' => 'integer',
        ]);
    }

    public function remaining(): int
    {
        return max(0, $this->max_harvest - $this->current_harvest);
    }
}
