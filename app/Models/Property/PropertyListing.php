<?php

namespace App\Models\Property;

use App\Models\BaseModelWithSoftDeletes;

class PropertyListing extends BaseModelWithSoftDeletes
{
    protected $connection = 'property';
    protected $table      = 'property_listings';

    protected $fillable = [
        'property_id',
        'listing_type',
        'status',
        'season_start',
        'season_end',
        'min_hunters',
        'max_hunters',
        'price_per_hunter',
        'price_per_hunter_weekly',
        'price_total',
        'deposit_amount',
        'deposit_percent',
        'booking_deposit_amount',
        'booking_deposit_percent',
        'auto_renew',
        'visibility',
        'is_featured',
        'early_termination_rent_policy',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'price_per_hunter'        => 'decimal:2',
            'price_per_hunter_weekly' => 'decimal:2',
            'price_total'             => 'decimal:2',
            'deposit_amount'   => 'decimal:2',
            'booking_deposit_amount' => 'decimal:2',
            'auto_renew'       => 'boolean',
            'is_featured'      => 'boolean',
            'season_start'     => 'date',
            'season_end'       => 'date',
        ]);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function amenities(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PropertyAmenity::class, 'property_amenity_listings', 'listing_id', 'amenity_id')
                    ->withPivot('notes');
    }

    public function availability(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyAvailability::class, 'listing_id');
    }

    public function savedBy(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SavedProperty::class, 'listing_id');
    }

    public function isAvailable(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end): bool
    {
        return ! $this->availability()
            ->where('date_start', '<=', $end->toDateString())
            ->where('date_end', '>=', $start->toDateString())
            ->exists();
    }
}
