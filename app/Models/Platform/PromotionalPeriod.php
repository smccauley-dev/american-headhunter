<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class PromotionalPeriod extends Model
{
    protected $connection = 'platform';
    protected $table      = 'promotional_periods';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'promo_key',
        'name',
        'description',
        'promotion_type',
        'status',
        'starts_at',
        'ends_at',
        'max_claims',
        'claims_count',
        'discount_pct',
        'discount_cents',
        'tier_grant_plan_id',
        'trial_days',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'      => 'datetime',
            'ends_at'        => 'datetime',
            'max_claims'     => 'integer',
            'claims_count'   => 'integer',
            'discount_pct'   => 'decimal:2',
            'discount_cents' => 'integer',
            'trial_days'     => 'integer',
            'rules'          => 'array',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    public function tierGrantPlan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'tier_grant_plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (is_null($this->starts_at) || $this->starts_at->isPast())
            && (is_null($this->ends_at) || $this->ends_at->isFuture())
            && (is_null($this->max_claims) || $this->claims_count < $this->max_claims);
    }
}
