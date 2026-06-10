<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

class UserLegalAcceptance extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'user_legal_acceptances';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'user_id',
        'document_key',
        'document_version',
        'accepted_at',
        'ip_address',
        'user_agent',
        'context_type',
        'context_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'accepted_at' => 'datetime',
            'created_at'  => 'datetime',
        ]);
    }
}
