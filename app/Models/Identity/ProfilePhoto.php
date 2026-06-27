<?php

namespace App\Models\Identity;

use App\Models\BaseModelWithSoftDeletes;

class ProfilePhoto extends BaseModelWithSoftDeletes
{
    protected $connection = 'identity';
    protected $table      = 'profile_photos';

    protected $fillable = [
        'user_id',
        'document_id',
        'caption',
        'description',
        'tags',
        'latitude',
        'longitude',
        'location_name',
        'exif_latitude',
        'exif_longitude',
        'sort_order',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'tags'           => 'array',
            'latitude'       => 'float',
            'longitude'      => 'float',
            'exif_latitude'  => 'float',
            'exif_longitude' => 'float',
            'sort_order'     => 'integer',
        ]);
    }
}
