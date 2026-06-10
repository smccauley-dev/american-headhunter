<?php

namespace App\Models\Documents;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends BaseModelWithSoftDeletes
{
    protected $connection = 'documents';
    protected $table      = 'documents';

    protected $fillable = [
        'owner_user_id',
        'document_type',
        'status',
        'original_filename',
        'mime_type',
        'size_bytes',
        'storage_bucket',
        'storage_key',
        'storage_provider',
        'width_px',
        'height_px',
        'duration_seconds',
        'checksum_sha256',
        'is_public',
        'virus_scan_status',
        'virus_scanned_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_public'        => 'boolean',
            'size_bytes'       => 'integer',
            'width_px'         => 'integer',
            'height_px'        => 'integer',
            'duration_seconds' => 'integer',
            'virus_scanned_at' => 'datetime',
        ]);
    }

    public function thumbnails(): HasMany
    {
        return $this->hasMany(DocumentThumbnail::class, 'document_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->virus_scan_status === 'clean';
    }

    public function isVirusScanPending(): bool
    {
        return $this->virus_scan_status === 'pending';
    }
}
