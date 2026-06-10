<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class TenantSettings extends Model
{
    protected $connection = 'platform';
    protected $table      = 'tenant_settings';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

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
