<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class ProfileTemplate extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'profile_templates';

    protected $fillable = [
        'profile_type',
        'name',
        'description',
        'draft_config',
        'published_config',
        'published_at',
        'published_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'draft_config'     => 'array',
            'published_config' => 'array',
            'published_at'     => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }
}
