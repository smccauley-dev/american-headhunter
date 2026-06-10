<?php

namespace App\Models\Geospatial;

use App\Models\BaseModel;

class TrailCameraLocation extends BaseModel
{
    // History preserved — no deleted_at; inactive cameras are marked in DB 5 (Wildlife)
    protected $connection = 'geospatial';
    protected $table      = 'trail_camera_locations';

    protected $fillable = [
        'camera_id',
        'facing_direction',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'facing_direction' => 'integer',
        ]);
    }
}
