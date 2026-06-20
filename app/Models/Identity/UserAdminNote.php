<?php

namespace App\Models\Identity;

use App\Models\BaseModel;

class UserAdminNote extends BaseModel
{
    protected $connection = 'identity';
    protected $table      = 'user_admin_notes';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'user_id',
        'author_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function scopeForUser($query, string $userId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('user_id', $userId)->orderByDesc('created_at');
    }

    // Cross-DB: resolved via UserService
    public function getAuthor(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->author_user_id);
    }
}
