<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

class PlanVersion extends Model
{
    protected $connection = 'platform';
    protected $table      = 'plan_versions';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false;

    // Plan versions are logically immutable — PostgreSQL RULE blocks UPDATE.
    // Do not call save() or update() on these records.
    protected $fillable = [
        'plan_id',
        'version_number',
        'plan_key',
        'display_name',
        'monthly_price_cents',
        'annual_price_cents',
        'platform_fee_pct',
        'commission_pct',
        'stripe_price_id_monthly',
        'stripe_price_id_annual',
        'entitlements_snapshot',
        'effective_from',
        'superseded_at',
        'change_reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number'      => 'integer',
            'monthly_price_cents' => 'integer',
            'annual_price_cents'  => 'integer',
            'platform_fee_pct'    => 'decimal:2',
            'commission_pct'      => 'decimal:2',
            'entitlements_snapshot' => 'array',
            'effective_from'      => 'datetime',
            'superseded_at'       => 'datetime',
            'created_at'          => 'datetime',
        ];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }
}
