<?php

namespace App\Models\Property;

use App\Models\BaseModelWithSoftDeletes;

class Property extends BaseModelWithSoftDeletes
{
    protected $connection = 'property';
    protected $table      = 'properties';

    /**
     * Placeholder owner used by older dev seed data before a real landowner
     * existed. Has no identity row. Treated as "unset" for owner-sync purposes.
     */
    public const PLACEHOLDER_OWNER_ID = '00000000-0000-4000-8000-000000000001';

    protected $fillable = [
        'owner_user_id',
        'title',
        'slug',
        'description',
        'status',
        'state_code',
        'county',
        'total_acres',
        'huntable_acres',
        'center_lat',
        'center_lng',
        'boundary_geospatial_id',
        'primary_photo_document_id',
    ];

    protected $hidden = ['address_encrypted'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'total_acres'    => 'decimal:2',
            'huntable_acres' => 'decimal:2',
            'center_lat'     => 'float',
            'center_lng'     => 'float',
        ]);
    }

    public function listings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyListing::class, 'property_id');
    }

    public function activeListings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyListing::class, 'property_id')
                    ->where('status', 'active')
                    ->whereNull('deleted_at');
    }

    public function photos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyPhoto::class, 'property_id')
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order');
    }

    public function species(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertySpecies::class, 'property_id');
    }

    public function amenities(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PropertyAmenity::class, 'property_amenity_offerings', 'property_id', 'amenity_id');
    }

    public function rules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyRule::class, 'property_id')
                    ->orderBy('sort_order');
    }

    public function contacts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyContact::class, 'property_id')
                    ->whereNull('deleted_at')
                    ->orderBy('sort_order')
                    ->orderBy('created_at');
    }

    // Cross-DB: resolved via UserService — do not use Eloquent belongsTo
    public function getOwner(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->owner_user_id);
    }

    // Cross-DB: resolved via GeospatialService — do not use Eloquent belongsTo
    public function getBoundary(): ?\App\Models\Geospatial\PropertyBoundary
    {
        if (! $this->boundary_geospatial_id) {
            return null;
        }
        return app(\App\Services\Property\GeospatialService::class)
            ->getBoundary($this->boundary_geospatial_id);
    }
}
