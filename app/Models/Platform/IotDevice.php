<?php

namespace App\Models\Platform;

use App\Models\BaseModelWithSoftDeletes;

class IotDevice extends BaseModelWithSoftDeletes
{
    protected $connection = 'platform';
    protected $table      = 'iot_devices';

    // config may contain credentials — never log this model
    protected $fillable = [
        'device_type',
        'name',
        'serial_number',
        'owner_user_id',
        'property_id',
        'config',
        'firmware_version',
        'is_active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'config'       => 'array',
            'is_active'    => 'boolean',
            'last_seen_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }
}
