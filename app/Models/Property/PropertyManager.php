<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyManager extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_managers';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'property_id',
        'user_id',
        'role',
        'granted_by_user_id',
        'granted_at',
        'revoked_at',
        'is_field_contact',
    ];

    protected function casts(): array
    {
        return [
            'granted_at'       => 'datetime',
            'revoked_at'       => 'datetime',
            'created_at'       => 'datetime',
            'is_field_contact' => 'boolean',
        ];
    }

    public function scopeActive($query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    // Cross-DB: resolved via UserService
    public function getUser(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->find($this->user_id);
    }

    public function getGrantedBy(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->find($this->granted_by_user_id);
    }
}
