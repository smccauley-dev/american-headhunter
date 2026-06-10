<?php

namespace App\Models\Identity;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OauthConnection extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'oauth_connections';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
        'token_expires_at',
    ];

    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'token_expires_at' => 'datetime',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
