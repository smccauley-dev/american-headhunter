<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertySpecies extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_species';

    protected $fillable = [
        'property_id',
        'species_code',
        'is_primary',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_primary' => 'boolean',
        ]);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
}
