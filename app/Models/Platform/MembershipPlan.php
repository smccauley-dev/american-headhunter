<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use SoftDeletes;

    protected $connection = 'platform';
    protected $table      = 'membership_plans';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

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
}
