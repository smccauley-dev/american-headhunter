<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseHunter extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'lease_hunters';

    protected $fillable = [
        'lease_id',
        'user_id',
        'role',
        'is_approved',
        'approved_at',
        'invited_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'invited_at'  => 'datetime',
        ]);
    }

    // ── Relationships within DB 3 ─────────────────────────────────────────────

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'lease_id');
    }

    // ── Cross-DB getters ──────────────────────────────────────────────────────

    public function getUser(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->user_id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPrimary(): bool
    {
        return $this->role === 'primary';
    }
}
