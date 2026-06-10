<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubMember extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'club_members';

    protected $fillable = [
        'club_id',
        'user_id',
        'role',
        'status',
        'joined_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'joined_at' => 'datetime',
        ]);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function getUser(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->find($this->user_id);
    }
}
