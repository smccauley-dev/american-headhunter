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

    /** Default pin color per marker type — overridable per marker via `color`. */
    public const TYPE_COLORS = [
        'amenity' => '#1d4ed8',
        'game'    => '#b91c1c',
        'stand'   => '#92400e',
        'camera'  => '#6b21a8',
        'access'  => '#0f766e',
        'hazard'  => '#ea580c',
        'water'   => '#0369a1',
        'other'   => '#374151',
    ];

    protected $fillable = [
        'map_image_id',
        'label',
        'marker_type',
        'x_percent',
        'y_percent',
        'latitude',
        'longitude',
        'color',
        'notes',
    ];

    /** Effective pin color — explicit override or the type default. */
    public function displayColor(): string
    {
        return $this->color ?: (self::TYPE_COLORS[$this->marker_type] ?? '#374151');
    }

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
