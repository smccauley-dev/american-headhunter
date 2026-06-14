<?php

namespace App\Models\Property;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyContact extends BaseModelWithSoftDeletes
{
    protected $connection = 'property';
    protected $table      = 'property_contacts';

    /** Editable contact categories. Landowner/managers are derived, not stored here. */
    public const TYPES = [
        'law_enforcement' => 'Local Law Enforcement',
        'game_warden'     => 'Game Warden / Conservation Officer',
        'emergency'       => 'Emergency (Hospital / 911)',
        'other'           => 'Other Contact',
    ];

    protected $fillable = [
        'property_id',
        'contact_type',
        'label',
        'name',
        'organization',
        'phone',
        'email',
        'address',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'sort_order' => 'integer',
        ]);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /** Display heading for this contact — the custom label for "other", else the type label. */
    public function displayLabel(): string
    {
        if ($this->contact_type === 'other') {
            return $this->label ?: 'Other Contact';
        }

        return self::TYPES[$this->contact_type] ?? 'Contact';
    }
}
