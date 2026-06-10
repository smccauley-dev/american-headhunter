<?php

namespace App\Models\Documents;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCode extends BaseModelWithSoftDeletes
{
    protected $connection = 'documents';
    protected $table      = 'qr_codes';

    protected $fillable = [
        'code_type',
        'target_id',
        'target_type',
        'token',
        'document_id',
        'expires_at',
        'last_scanned_at',
        'scan_count',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'expires_at'      => 'datetime',
            'last_scanned_at' => 'datetime',
            'scan_count'      => 'integer',
        ]);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->trashed();
    }
}
