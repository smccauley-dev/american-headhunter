<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyAmenity extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_amenities';

    protected $fillable = [
        'name',
        'category',
        'icon_name',
    ];

    public static function categoryLabel(string $category): string
    {
        static $labels = [
            'accommodation' => 'Accommodation',
            'access'        => 'Access',
            'water'         => 'Water Features',
            'stand'         => 'Stands & Blinds',
            'food_plot'     => 'Food & Plots',
            'other'         => 'Other',
        ];
        return $labels[$category] ?? ucwords(str_replace('_', ' ', $category));
    }

    public function listings(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PropertyListing::class, 'property_amenity_listings', 'amenity_id', 'listing_id')
                    ->withPivot('notes');
    }
}
