<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IotDevice extends Model
{
    use SoftDeletes;

    protected $connection = 'platform';
    protected $table      = 'iot_devices';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

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
