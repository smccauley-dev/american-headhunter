<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirstResponderVerification extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'first_responder_verifications';

    protected $fillable = [
        'user_id',
        'method',
        'status',
        'document_id',
        'id_me_uuid',
        'verified_at',
        'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'verified_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
