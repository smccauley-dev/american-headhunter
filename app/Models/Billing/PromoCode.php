<?php

namespace App\Models\Billing;

use App\Models\BaseModelWithSoftDeletes;

class PromoCode extends BaseModelWithSoftDeletes
{
    protected $connection = 'billing';
    protected $table      = 'promo_codes';

    protected $fillable = [
        'promotional_period_id',
        'code',
        'owner_user_id',
        'max_redemptions',
        'redemption_count',
        'per_user_limit',
        'starts_at',
        'expires_at',
        'is_active',
        'created_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'max_redemptions'  => 'integer',
            'redemption_count' => 'integer',
            'per_user_limit'   => 'integer',
            'starts_at'        => 'datetime',
            'expires_at'       => 'datetime',
            'is_active'        => 'boolean',
        ]);
    }
}
