<?php

namespace App\Models\Documents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No updated_at, no deleted_at — thumbnails are replaced by deleting and re-inserting
class DocumentThumbnail extends BaseModel
{
    protected $connection = 'documents';
    protected $table      = 'document_thumbnails';

    protected $fillable = [
        'document_id',
        'variant',
        'storage_key',
        'width_px',
        'height_px',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'width_px'   => 'integer',
            'height_px'  => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
