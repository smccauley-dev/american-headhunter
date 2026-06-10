<?php

namespace App\Models\Platform;

use App\Models\BaseModel;

class LegalDocument extends BaseModel
{
    protected $connection = 'platform';
    protected $table      = 'legal_documents';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'document_key',
        'version',
        'title',
        'content',
        'effective_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'effective_date' => 'date',
            'is_active'      => 'boolean',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ]);
    }

    public static function getActive(string $key): ?self
    {
        return static::where('document_key', $key)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
    }
}
