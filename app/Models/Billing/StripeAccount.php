<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class StripeAccount extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'stripe_accounts';

    protected $fillable = [
        'user_id',
        'stripe_account_id',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'charges_enabled'         => 'boolean',
            'payouts_enabled'         => 'boolean',
            'details_submitted'       => 'boolean',
            'onboarding_completed_at' => 'datetime',
        ]);
    }
}
