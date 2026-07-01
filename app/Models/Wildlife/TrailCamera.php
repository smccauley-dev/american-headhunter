<?php

namespace App\Models\Wildlife;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrailCamera extends BaseModelWithSoftDeletes
{
    protected $connection = 'wildlife';

    protected $table = 'trail_cameras';

    protected $fillable = [
        'lease_id',
        'property_id',
        'user_id',
        'name',
        'model',
        'location_geospatial_id',
        'status',
        'last_photo_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_photo_at' => 'datetime',
        ]);
    }

    // Same-DB relationship (both tables in DB 5).
    public function photos(): HasMany
    {
        return $this->hasMany(TrailCameraPhoto::class, 'camera_id')->whereNull('deleted_at');
    }
}
