<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;

class WildlifeSighting extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'wildlife_sightings';

    protected $fillable = [
        'lease_id',
        'user_id',
        'property_id',
        'species_code',
        'sighting_date',
        'sighting_time',
        'count',
        'location_geospatial_id',
        'notes',
        'photo_document_ids',
        'hide_location_from_members',
        'local_record_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'sighting_date' => 'date',
            'count' => 'integer',
            'photo_document_ids' => 'array',
            'hide_location_from_members' => 'boolean',
        ]);
    }
}
