<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Club extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'clubs';

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'description',
        'status',
        'max_members',
        'membership_fee',
        'is_public',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'membership_fee' => 'decimal:2',
            'is_public'      => 'boolean',
            'max_members'    => 'integer',
        ]);
    }

    // ── Relationships within DB 3 ─────────────────────────────────────────────

    public function members(): HasMany
    {
        return $this->hasMany(ClubMember::class, 'club_id')->whereNull('deleted_at');
    }

    public function clubLeases(): HasMany
    {
        return $this->hasMany(ClubLease::class, 'club_id');
    }

    // ── Cross-DB getters ──────────────────────────────────────────────────────

    public function getOwner(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->owner_user_id);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
