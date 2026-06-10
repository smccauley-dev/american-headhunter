<?php

namespace App\Models\Geospatial;

use App\Models\BaseModel;

class CwdManagementZone extends BaseModel
{
    // ETL-managed — superseded zones get new rows with a new effective_date; no deleted_at
    protected $connection = 'geospatial';
    protected $table      = 'cwd_management_zones';

    protected $fillable = [
        'state_code',
        'zone_name',
        'zone_type',
        'effective_date',
        'source_url',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'effective_date' => 'date',
        ]);
    }
}
