<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyMapMarker extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_map_markers';

    public const TYPES = [
        'amenity' => 'Amenity',
        'game'    => 'Game Location',
        'stand'   => 'Stand / Blind',
        'camera'  => 'Trail Camera',
        'access'  => 'Access / Gate',
        'hazard'  => 'Hazard',
        'water'   => 'Water',
        'other'   => 'Other',
    ];

    protected $fillable = [
        'map_image_id',
        'label',
        'marker_type',
        'x_percent',
        'y_percent',
        'latitude',
        'longitude',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'x_percent'  => 'float',
            'y_percent'  => 'float',
            'latitude'   => 'float',
            'longitude'  => 'float',
            'deleted_at' => 'datetime',
        ]);
    }

    public function mapImage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PropertyMapImage::class, 'map_image_id');
    }
}
