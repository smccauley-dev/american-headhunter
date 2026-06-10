<?php

namespace App\Models\Geospatial;

use App\Models\BaseModel;

class HarvestLocation extends BaseModel
{
    // Immutable — written once at harvest submission, never updated or deleted
    protected $connection = 'geospatial';
    protected $table      = 'harvest_locations';

    protected $fillable = [
        'harvest_log_id',
        'accuracy_meters',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'accuracy_meters' => 'integer',
        ]);
    }
}
