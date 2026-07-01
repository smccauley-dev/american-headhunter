<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;

class TrailCameraPhoto extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'trail_camera_photos';

    protected $fillable = [
        'camera_id',
        'document_id',
        'taken_at',
        'species_detected',
        'ai_processed_at',
        'ai_confidence',
        'is_flagged',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'taken_at' => 'datetime',
            'species_detected' => 'array',
            'ai_processed_at' => 'datetime',
            'ai_confidence' => 'decimal:3',
            'is_flagged' => 'boolean',
        ]);
    }
}
