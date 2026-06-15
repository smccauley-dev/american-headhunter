<?php

namespace App\Models\Billing;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCalculation extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'tax_calculations';

    protected $fillable = [
        'payment_id',
        'taxjar_transaction_id',
        'state_code',
        'tax_rate',
        'amount_taxable_cents',
        'tax_cents',
    ];

    // Append-only table — only created_at exists (no updated_at, no deleted_at).
    protected function casts(): array
    {
        return [
            'tax_rate'             => 'decimal:4',
            'amount_taxable_cents' => 'integer',
            'tax_cents'            => 'integer',
            'created_at'           => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
