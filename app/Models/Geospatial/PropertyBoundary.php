<?php

namespace App\Models\Geospatial;

use App\Models\BaseModelWithSoftDeletes;

class PropertyBoundary extends BaseModelWithSoftDeletes
{
    protected $connection = 'geospatial';
    protected $table      = 'property_boundaries';

    // boundary is a PostGIS geometry column — read via GeospatialService::getBoundary(), never as an Eloquent attribute
    protected $fillable = [
        'property_id',
        'source',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'area_acres' => 'decimal:4',
        ]);
    }
}
