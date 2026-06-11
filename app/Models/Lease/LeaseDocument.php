<?php

namespace App\Models\Lease;

use App\Enums\LeaseDocumentTag;
use App\Models\BaseModel;

class LeaseDocument extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'lease_documents';

    protected $fillable = [
        'lease_id',
        'document_id',
        'tag',
        'uploaded_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'tag'        => LeaseDocumentTag::class,
            'created_at' => 'datetime',
            'deleted_at' => 'datetime',
        ]);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }
}
