<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EsignatureRequest extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'esignature_requests';

    protected $fillable = [
        'lease_id',
        'provider_request_id',
        'status',
        'document_document_id',
        'requested_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ]);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }
}
