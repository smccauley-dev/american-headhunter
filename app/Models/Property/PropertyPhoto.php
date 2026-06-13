<?php

namespace App\Models\Property;

use App\Models\BaseModel;

class PropertyPhoto extends BaseModel
{
    protected $connection = 'property';
    protected $table      = 'property_photos';

    protected $fillable = [
        'property_id',
        'document_id',
        'sort_order',
        'caption',
        'tags',
        'latitude',
        'longitude',
        'is_primary',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'tags'       => 'array',
            'latitude'   => 'float',
            'longitude'  => 'float',
            'deleted_at' => 'datetime',
        ]);
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    // Cross-DB: resolve document URL via DocumentService — never query documents DB directly
    public function getUrl(): string
    {
        return app(\App\Services\Documents\DocumentService::class)->getUrl($this->document_id);
    }
}
