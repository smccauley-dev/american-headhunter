<?php

namespace App\Models\Documents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EsignatureRequest extends BaseModel
{
    protected $connection = 'documents';
    protected $table      = 'esignature_requests';

    protected $fillable = [
        'lease_id',
        'requester_user_id',
        'provider',
        'provider_signature_request_id',
        'status',
        'subject',
        'message',
        'template_document_id',
        'signed_document_id',
        'requested_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at'   => 'datetime',
        ]);
    }

    // ── Relationships within DB 11 ────────────────────────────────────────────

    public function signers(): HasMany
    {
        return $this->hasMany(EsignatureSigner::class, 'request_id');
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function allSigned(): bool
    {
        return $this->signers()->where('status', '!=', 'signed')->doesntExist();
    }
}
