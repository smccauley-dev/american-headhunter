<?php

namespace App\Models\Geospatial;

use App\Models\BaseModel;

class SosLocation extends BaseModel
{
    // Permanent life-safety record — never update or delete
    protected $connection = 'geospatial';
    protected $table      = 'sos_locations';

    protected $fillable = [
        'sos_event_log_id',
        'accuracy_meters',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'accuracy_meters' => 'integer',
            'recorded_at'     => 'datetime',
        ]);
    }
}
