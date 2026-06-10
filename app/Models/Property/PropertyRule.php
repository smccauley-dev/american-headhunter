<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyRule extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_rules';

    protected $fillable = [
        'property_id',
        'rule_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'sort_order' => 'integer',
        ]);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }
}
