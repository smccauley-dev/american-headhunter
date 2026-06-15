<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class TenantSettings extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'tenant_settings';

    protected $fillable = [
        'key',
        'value',
        'description',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'value'      => 'array',
            'is_public'  => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
