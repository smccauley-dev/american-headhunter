<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * Vet-first booking fee (DB 4). System-authored: written only by the trusted
 * ah_system path (booking-fee webhook, deadline-enforcement command, Filament
 * admin); read under ah_runtime scoped by RLS to the two parties (applicant +
 * landowner) and staff.
 *
 * The fee is application-scoped: it is paid after approval but before a lease
 * exists (application_id), and lease_id is backfilled when the paying applicant
 * wins the spot. It is HELD on the platform (a plain charge) and routed on outcome:
 *  - 'held'      — paid, awaiting the lease outcome (credited toward the lease total)
 *  - 'disbursed' — lease completed; the fee was released to the landowner
 *  - 'forfeited' — the 7-day window lapsed; the fee was kept by the landowner
 *  - 'refunded'  — the applicant lost the first-to-pay race; the fee was returned
 * ('pending'/'collected' linger from the pre-vet destination-charge model.)
 *
 * No soft deletes — a booking fee is a financial record that resolves via $status.
 */
class BookingDeposit extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'booking_deposits';

    protected $fillable = [
        'lease_id',
        'application_id',
        'payer_user_id',
        'payee_user_id',
        'payment_id',
        'payout_id',
        'stripe_account_id',
        'amount_cents',
        'application_fee_cents',
        'net_cents',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'stripe_transfer_id',
        'collected_at',
        'disbursed_at',
        'forfeited_at',
        'refunded_at',
    ];

    // Stripe identifiers never reach the client and must never be logged.
    protected $hidden = [
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'stripe_transfer_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents'          => 'integer',
            'application_fee_cents' => 'integer',
            'net_cents'             => 'integer',
            'collected_at'          => 'datetime',
            'disbursed_at'          => 'datetime',
            'forfeited_at'          => 'datetime',
            'refunded_at'           => 'datetime',
        ]);
    }

    /** Whether the fee is paid and awaiting the lease outcome. */
    public function isHeld(): bool
    {
        return $this->status === 'held';
    }

    /** Whether the fee has been routed to the landowner (completion or forfeiture). */
    public function isDisbursed(): bool
    {
        return in_array($this->status, ['disbursed', 'forfeited'], true);
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
