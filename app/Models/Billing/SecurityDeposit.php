<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * Refundable lease security deposit (DB 4). System-authored: written only by the
 * trusted ah_system path (deposit-charge webhook, release/forfeit jobs, Filament
 * admin); read under ah_runtime scoped by RLS to the two parties (lessee +
 * landowner) and staff. A separate captured charge funds the hold; release issues
 * a Stripe refund and forfeiture disburses to the landowner via a payout.
 *
 * No soft deletes — a deposit is a financial record that resolves via $status.
 */
class SecurityDeposit extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'security_deposits';

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'payment_id',
        'amount_cents',
        'refunded_amount_cents',
        'forfeited_amount_cents',
        'currency',
        'status',
        'forfeit_reason',
        'stripe_payment_intent_id',
        'stripe_refund_id',
        'held_at',
        'released_at',
    ];

    // Stripe identifiers never reach the client and must never be logged.
    protected $hidden = [
        'stripe_payment_intent_id',
        'stripe_refund_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents'           => 'integer',
            'refunded_amount_cents'  => 'integer',
            'forfeited_amount_cents' => 'integer',
            'held_at'                => 'datetime',
            'released_at'            => 'datetime',
        ]);
    }

    /** Cents still held — not yet refunded or forfeited. */
    public function remainingCents(): int
    {
        return (int) $this->amount_cents
            - (int) $this->refunded_amount_cents
            - (int) $this->forfeited_amount_cents;
    }

    /** Whether the deposit has been fully resolved (released, refunded, or forfeited). */
    public function isResolved(): bool
    {
        return in_array($this->status, ['released', 'refunded', 'forfeited'], true);
    }

    /**
     * The lease this deposit secures. Cross-DB (DB 3) — resolved via the service
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
