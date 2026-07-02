<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;

class HarvestLog extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'harvest_logs';

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
        'hide_location_from_members',
        'local_record_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'harvest_date' => 'date',
            'field_photos' => 'array',
            'is_public' => 'boolean',
            'hide_location_from_members' => 'boolean',
            'antler_score' => 'decimal:2',
            'weight_lbs' => 'decimal:2',
            'ai_score' => 'decimal:2',
            'ai_scored_at' => 'datetime',
        ]);
    }
}
