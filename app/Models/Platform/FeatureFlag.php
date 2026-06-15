<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class FeatureFlag extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'feature_flags';

    protected $fillable = [
        'key',
        'display_name',
        'description',
        'is_enabled',
        'enabled_for_roles',
        'enabled_for_user_ids',
        'rollout_percentage',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'           => 'boolean',
            'enabled_for_roles'    => 'array',
            'enabled_for_user_ids' => 'array',
            'rollout_percentage'   => 'integer',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }
}
