<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;

class FishingHarvestLog extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'fishing_harvest_logs';

    protected $fillable = [
        'lease_id',
        'user_id',
        'property_id',
        'species_code',
        'catch_date',
        'catch_time',
        'location_geospatial_id',
        'length_inches',
        'weight_lbs',
        'catch_and_release',
        'field_photos',
        'notes',
        'is_public',
        'local_record_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'catch_date' => 'date',
            'field_photos' => 'array',
            'catch_and_release' => 'boolean',
            'is_public' => 'boolean',
            'length_inches' => 'decimal:2',
            'weight_lbs' => 'decimal:2',
        ]);
    }
}
