<?php

namespace App\Models\Geospatial;

use App\Models\BaseModelWithSoftDeletes;

class StandLocation extends BaseModelWithSoftDeletes
{
    protected $connection = 'geospatial';
    protected $table      = 'stand_locations';

    protected $fillable = [
        'property_id',
        'lease_id',
        'name',
        'stand_type',
        'elevation_ft',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
        ]);
    }
}
