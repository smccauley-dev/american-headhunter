<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyAccessInfo extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_access_info';

    // Never expose the encrypted column directly.
    // Always use PropertyService::getAccessInfo($propertyId).
    protected $hidden = ['access_info_encrypted'];

    protected $fillable = [
        'property_id',
        'access_info_encrypted',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'updated_at' => 'datetime',
        ]);
    }
}
