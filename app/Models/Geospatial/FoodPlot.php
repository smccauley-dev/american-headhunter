<?php

namespace App\Models\Geospatial;

use App\Models\BaseModelWithSoftDeletes;

class FoodPlot extends BaseModelWithSoftDeletes
{
    protected $connection = 'geospatial';
    protected $table      = 'food_plots';

    protected $fillable = [
        'property_id',
        'name',
        'species_planted',
        'planted_date',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'species_planted' => 'array',
            'area_acres'      => 'decimal:4',
            'planted_date'    => 'date',
        ]);
    }
}
