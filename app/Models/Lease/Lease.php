<?php

namespace App\Models\Lease;

use App\Models\BaseModelWithSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lease extends BaseModelWithSoftDeletes
{
    protected $connection = 'lease';
    protected $table      = 'leases';

    protected $fillable = [
        'application_id',
        'property_id',
        'listing_id',
        'lessee_user_id',
        'lessor_user_id',
        'status',
        'start_date',
        'end_date',
        'total_price',
        'deposit_paid',
        'auto_renew',
        'terminated_at',
        'termination_reason',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'start_date'      => 'date',
            'end_date'        => 'date',
            'total_price'     => 'decimal:2',
            'deposit_paid'    => 'decimal:2',
            'auto_renew'      => 'boolean',
            'terminated_at'   => 'datetime',
        ]);
    }

    // ── Relationships within DB 3 ─────────────────────────────────────────────

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaseApplication::class, 'application_id');
    }

    public function hunters(): HasMany
    {
        return $this->hasMany(LeaseHunter::class, 'lease_id')->whereNull('deleted_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LeaseNote::class, 'lease_id')->whereNull('deleted_at');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class, 'lease_id');
    }

    public function signatureEvents(): HasMany
    {
        return $this->hasMany(SignatureEvent::class, 'lease_id');
    }

    public function esignatureRequest(): HasOne
    {
        return $this->hasOne(EsignatureRequest::class, 'lease_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(LeaseRenewal::class, 'lease_id');
    }

    // ── Cross-DB getters ──────────────────────────────────────────────────────

    public function getProperty(): ?\App\Models\Property\Property
    {
        return app(\App\Services\Property\PropertyService::class)->find($this->property_id);
    }

    public function getLessee(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->lessee_user_id);
    }

    public function getLessor(): ?\App\Models\Identity\User
    {
        return app(\App\Services\Identity\UserService::class)->findById($this->lessor_user_id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForLessee($query, string $userId)
    {
        return $query->where('lessee_user_id', $userId);
    }

    public function scopeForLessor($query, string $userId)
    {
        return $query->where('lessor_user_id', $userId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPendingSignatures(): bool
    {
        return $this->status === 'pending_signatures';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->end_date->isPast();
    }
}
