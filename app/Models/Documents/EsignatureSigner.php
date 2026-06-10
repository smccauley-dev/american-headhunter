<?php

namespace App\Models\Documents;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// No updated_at, no deleted_at — signer records are permanent once created
class EsignatureSigner extends BaseModel
{
    protected $connection = 'documents';
    protected $table      = 'esignature_signers';

    protected $fillable = [
        'request_id',
        'user_id',
        'email',
        'name',
        'order_num',
        'status',
        'signed_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'order_num'   => 'integer',
            'signed_at'   => 'datetime',
            'declined_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(EsignatureRequest::class, 'request_id');
    }

    public function hasSigned(): bool
    {
        return $this->status === 'signed';
    }
}
