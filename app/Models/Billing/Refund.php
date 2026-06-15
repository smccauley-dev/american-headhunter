<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'refunds';

    protected $fillable = [
        'payment_id',
        'amount_cents',
        'reason',
        'status',
        'stripe_refund_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents' => 'integer',
            'processed_at' => 'datetime',
        ]);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
