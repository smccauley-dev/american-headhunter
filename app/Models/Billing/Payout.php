<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

class Payout extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'payouts';

    // Never expose Stripe identifiers.
    protected $hidden = ['stripe_payout_id', 'stripe_transfer_id'];

    protected $fillable = [
        'payee_user_id',
        'stripe_account_id',
        'amount_cents',
        'currency',
        'status',
        'stripe_payout_id',
        'stripe_transfer_id',
        'scheduled_for',
        'paid_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents'  => 'integer',
            'scheduled_for' => 'date',
            'paid_at'       => 'datetime',
            'reversed_at'   => 'datetime',
        ]);
    }
}
