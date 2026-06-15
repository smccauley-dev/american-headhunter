<?php

namespace App\Models\Billing;

use App\Models\BaseModelWithSoftDeletes;

class PaymentMethod extends BaseModelWithSoftDeletes
{
    protected $connection = 'billing';
    protected $table      = 'payment_methods';

    // Never expose the Stripe payment method token.
    protected $hidden = ['stripe_payment_method_id'];

    protected $fillable = [
        'user_id',
        'stripe_payment_method_id',
        'type',
        'brand',
        'last_four',
        'exp_month',
        'exp_year',
        'is_default',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'exp_month'  => 'integer',
            'exp_year'   => 'integer',
            'is_default' => 'boolean',
        ]);
    }
}
