<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyMapImage extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_map_images';

    protected $fillable = [
        'property_id',
        'document_id',
        'sort_order',
        'description',
        'latitude',
        'longitude',
        'is_boundary',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_boundary' => 'boolean',
            'sort_order'  => 'integer',
            'latitude'    => 'float',
            'longitude'   => 'float',
            'deleted_at'  => 'datetime',
        ]);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function markers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyMapMarker::class, 'map_image_id')
                    ->whereNull('deleted_at')
                    ->orderBy('created_at');
    }
}
