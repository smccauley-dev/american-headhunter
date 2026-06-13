<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class ProfileTemplate extends Model
{
    protected $connection = 'platform';
    protected $table      = 'profile_templates';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

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
