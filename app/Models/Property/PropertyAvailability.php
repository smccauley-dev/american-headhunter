<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyAvailability extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_availability';

    protected $fillable = [
        'listing_id',
        'date_start',
        'date_end',
        'reason',
        'cost',
        'hunter_count',
        'lease_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'date_start'   => 'date',
            'date_end'     => 'date',
            'cost'         => 'decimal:2',
            'hunter_count' => 'integer',
        ]);
    }

    public function listing(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id');
    }
}
