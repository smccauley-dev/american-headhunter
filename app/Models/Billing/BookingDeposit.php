<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * Non-refundable lease booking deposit / down payment (DB 4). System-authored:
 * written only by the trusted ah_system path (booking-deposit webhook, deferred
 * payout job, Filament admin); read under ah_runtime scoped by RLS to the two
 * parties (lessee + landowner) and staff. Distinct from SecurityDeposit — this is
 * earned on booking, credited toward the lease total, and disbursed to the
 * landowner; it has no release/forfeit lifecycle and resolves via $status.
 *
 * No soft deletes — a booking deposit is a financial record that resolves via $status.
 */
class BookingDeposit extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'booking_deposits';

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'payment_id',
        'payout_id',
        'amount_cents',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'collected_at',
        'disbursed_at',
    ];

    // The Stripe identifier never reaches the client and must never be logged.
    protected $hidden = [
        'stripe_payment_intent_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents' => 'integer',
            'collected_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ]);
    }

    /** Whether the deposit has been disbursed to the landowner. */
    public function isDisbursed(): bool
    {
        return $this->status === 'disbursed';
    }

    /**
     * The lease this deposit applies to. Cross-DB (DB 3) — resolved via the service
     * layer, never an Eloquent relationship.
     */
    public function getLease(): ?\App\Models\Lease\Lease
    {
        if (! $this->lease_id) {
            return null;
        }

        return app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);
    }
}
