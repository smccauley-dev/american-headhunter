<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class PromotionClaim extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'promotion_claims';

    protected $fillable = [
        'user_id',
        'promotion_period_id',
        'status',
        'granted_plan_id',
        'granted_plan_version_id',
        'duration_days',
        'discount_percentage',
        'discount_amount_cents',
        'activated_at',
        'expires_at',
        'converted_at',
        'cancelled_at',
        'trigger_event',
        'promo_code_used',
        'referral_source_user_id',
        'reminder_30d_sent_at',
        'reminder_7d_sent_at',
        'reminder_1d_sent_at',
        'applied_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'duration_days'         => 'integer',
            'discount_percentage'   => 'decimal:2',
            'discount_amount_cents' => 'integer',
            'activated_at'          => 'datetime',
            'expires_at'            => 'datetime',
            'converted_at'          => 'datetime',
            'cancelled_at'          => 'datetime',
            'reminder_30d_sent_at'  => 'datetime',
            'reminder_7d_sent_at'   => 'datetime',
            'reminder_1d_sent_at'   => 'datetime',
        ]);
    }
}
