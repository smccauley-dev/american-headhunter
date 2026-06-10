<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class SavedProperty extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'saved_properties';

    protected $fillable = [
        'user_id',
        'listing_id',
    ];

    public function listing(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PropertyListing::class, 'listing_id');
    }
}
