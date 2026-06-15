<?php

namespace App\Models\Platform;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionalPeriod extends Model
{
    protected $connection = 'platform';
    protected $table      = 'promotional_periods';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    protected $fillable = [
        'promo_key',
        'display_name',
        'description',
        'promotion_type',
        'status',
        'target_account_types',
        'target_states',
        'target_rules_json',
        'grants_plan_id',
        'duration_days',
        'discount_percentage',
        'discount_amount_cents',
        'referral_reward_type',
        'referral_reward_value',
        'on_expiration',
        'starts_at',
        'ends_at',
        'claim_limit',
        'claim_count',
        'per_user_limit',
        'stackable_with_other_promos',
        'stackable_with_veteran',
        'requires_promo_code',
        'auto_apply_on_signup',
        'auto_apply_on_first_listing',
        'show_on_landing',
        'show_on_pricing',
        'show_claim_counter',
        'landing_banner_text',
        'pricing_badge_text',
        'dashboard_callout_text',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'target_account_types'        => PgTextArray::class,
            'target_states'               => PgTextArray::class,
            'target_rules_json'           => 'array',
            'duration_days'               => 'integer',
            'discount_percentage'         => 'decimal:2',
            'discount_amount_cents'       => 'integer',
            'referral_reward_value'       => 'integer',
            'claim_limit'                 => 'integer',
            'claim_count'                 => 'integer',
            'per_user_limit'              => 'integer',
            'stackable_with_other_promos' => 'boolean',
            'stackable_with_veteran'      => 'boolean',
            'requires_promo_code'         => 'boolean',
            'auto_apply_on_signup'        => 'boolean',
            'auto_apply_on_first_listing' => 'boolean',
            'show_on_landing'             => 'boolean',
            'show_on_pricing'             => 'boolean',
            'show_claim_counter'          => 'boolean',
            'starts_at'                   => 'datetime',
            'ends_at'                     => 'datetime',
            'paused_at'                   => 'datetime',
            'ended_at'                    => 'datetime',
            'created_at'                  => 'datetime',
            'updated_at'                  => 'datetime',
        ];
    }

    /**
     * The plan this promotion grants (same connection — both live in DB 12).
     */
    public function grantsPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'grants_plan_id');
    }

    /**
     * Whether the promotion is currently claimable: active, inside its window,
     * and under its total claim limit (null claim_limit means unlimited).
     */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && (is_null($this->starts_at) || $this->starts_at->isPast())
            && (is_null($this->ends_at) || $this->ends_at->isFuture())
            && (is_null($this->claim_limit) || $this->claim_count < $this->claim_limit);
    }
}
