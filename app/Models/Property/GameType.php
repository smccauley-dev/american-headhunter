<?php

namespace App\Models\Property;

use App\Models\BaseModel;

/**
 * A huntable game type — the admin-managed registry behind property_species.
 * `code` is the slug referenced by property_species.species_code (FK), so it is
 * immutable once a type is in use. `icon_svg` holds sanitized inline SVG markup
 * rendered on public listings.
 */
class GameType extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'game_types';

    protected $fillable = [
        'code',
        'label',
        'icon_svg',
        'icon_viewbox',
        'default_availability',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ]);
    }
}
