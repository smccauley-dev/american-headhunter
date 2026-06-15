<?php

namespace App\Models\Billing;

use App\Models\BaseModelWithSoftDeletes;
use App\Models\Lease\Lease;
use App\Services\Lease\LeaseService;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends BaseModelWithSoftDeletes
{
    protected $connection = 'billing';
    protected $table      = 'invoices';

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'status',
        'subtotal_cents',
        'tax_cents',
        'platform_fee_cents',
        'total_cents',
        'currency',
        'stripe_invoice_id',
        'due_date',
        'paid_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'subtotal_cents'     => 'integer',
            'tax_cents'          => 'integer',
            'platform_fee_cents' => 'integer',
            'total_cents'        => 'integer',
            'due_date'           => 'date',
            'paid_at'            => 'datetime',
        ]);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    // Cross-DB (DB 3) — resolved via LeaseService, never an Eloquent relation.
    public function getLease(): ?Lease
    {
        if (! $this->lease_id) {
            return null;
        }

        return app(LeaseService::class)->find($this->lease_id);
    }
}
