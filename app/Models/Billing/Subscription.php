<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class Subscription extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'subscriptions';

    // Never expose Stripe identifiers.
    protected $hidden = ['stripe_subscription_id', 'stripe_customer_id'];

    protected $fillable = [
        'user_id',
        'plan_version_id',
        'active_promotion_claim_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'status',
        'billing_interval',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'current_period_start' => 'date',
            'current_period_end'   => 'date',
            'trial_ends_at'        => 'datetime',
            'cancelled_at'         => 'datetime',
        ]);
    }
}
