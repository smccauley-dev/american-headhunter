<?php

namespace App\Models\Platform;

use App\Models\BaseModelWithSoftDeletes;

class MembershipPlan extends BaseModelWithSoftDeletes
{
    protected $connection = 'platform';
    protected $table      = 'membership_plans';

    protected $fillable = [
        'plan_key',
        'account_type',
        'display_name',
        'description',
        'tagline',
        'monthly_price_cents',
        'annual_price_cents',
        'currency',
        'platform_fee_pct',
        'commission_pct',
        'monthly_enabled',
        'annual_enabled',
        'stripe_product_id',
        'stripe_monthly_price_id',
        'stripe_annual_price_id',
        'sort_order',
        'is_public',
        'is_active',
        'is_default_free',
        'admin_notes',
        'launched_at',
        'deprecated_at',
        'header_image_path',
        'accent_color',
        'badge_label',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price_cents' => 'integer',
            'annual_price_cents'  => 'integer',
            'platform_fee_pct'    => 'decimal:2',
            'commission_pct'      => 'decimal:2',
            'monthly_enabled'     => 'boolean',
            'annual_enabled'      => 'boolean',
            'is_public'           => 'boolean',
            'is_active'           => 'boolean',
            'is_default_free'     => 'boolean',
            'is_featured'         => 'boolean',
            'sort_order'          => 'integer',
            'launched_at'         => 'datetime',
            'deprecated_at'       => 'datetime',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
            'deleted_at'          => 'datetime',
        ];
    }

    public function versions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }

    public function entitlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FeatureEntitlement::class, 'plan_id')->orderBy('display_order');
    }

    public function currentVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PlanVersion::class, 'plan_id')
                    ->whereNull('superseded_at')
                    ->orderByDesc('version_number');
    }

    /**
     * Promo codes linked to this plan (same connection — DB 12). The linked
     * promo_code_id is a cross-DB reference to billing.promo_codes (DB 4).
     */
    public function promoCodeLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlanPromoCode::class, 'plan_id');
    }
}
