<?php

namespace App\Models\Lease;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only log — no updated_at, no deleted_at
class CheckIn extends BaseModel
{
    protected $connection = 'lease';
    protected $table      = 'check_ins';

    protected $fillable = [
        'lease_id',
        'user_id',
        'stand_location_id',
        'checked_in_at',
        'checked_out_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at'  => 'datetime',
            'checked_out_at' => 'datetime',
            'created_at'     => 'datetime',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    public function getUser(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->user_id);
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }
}
