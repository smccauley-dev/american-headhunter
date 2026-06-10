<?php

namespace App\Models\Wildlife;

use App\Models\BaseModel;

class HarvestLog extends BaseModel
{
    protected $connection = 'wildlife';
    protected $table      = 'harvest_logs';

    protected $fillable = [
        'lease_id',
        'user_id',
        'property_id',
        'species_code',
        'harvest_date',
        'harvest_time',
        'location_geospatial_id',
        'weapon_type',
        'antler_score',
        'weight_lbs',
        'age_estimate',
        'field_photos',
        'notes',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'harvest_date'   => 'date',
            'field_photos'   => 'array',
            'is_public'      => 'boolean',
            'antler_score'   => 'decimal:2',
            'weight_lbs'     => 'decimal:2',
            'ai_score'       => 'decimal:2',
            'ai_scored_at'   => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }
}
