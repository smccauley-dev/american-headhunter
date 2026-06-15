<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'payments';

    // Never expose or log Stripe identifiers.
    protected $hidden = ['stripe_payment_intent_id', 'stripe_charge_id'];

    protected $fillable = [
        'invoice_id',
        'payer_user_id',
        'amount_cents',
        'currency',
        'status',
        'payment_method_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents' => 'integer',
            'metadata'     => 'array',
        ]);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id');
    }

    public function taxCalculation(): HasOne
    {
        return $this->hasOne(TaxCalculation::class, 'payment_id');
    }
}
