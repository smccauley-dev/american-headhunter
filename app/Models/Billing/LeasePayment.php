<?php

namespace App\Models\Billing;

use App\Models\BaseModel;

/**
 * A lease-rent payment collected via Stripe Connect destination charge (DB 4). The
 * single source of truth for one charge: gross (customer paid), surcharge (kept by
 * platform), application_fee (platform revenue), and net (auto-transferred to the
 * landowner). System-authored — written only by the trusted ah_system path
 * (lease-payment webhook, db.system return route, Filament admin); read under
 * ah_runtime scoped by RLS to the two parties + staff.
 *
 * No soft deletes — a lease payment is a financial record that resolves via $status.
 */
class LeasePayment extends BaseModel
{
    protected $connection = 'billing';
    protected $table      = 'lease_payments';

    // Never expose Stripe identifiers.
    protected $hidden = ['stripe_payment_intent_id', 'stripe_charge_id', 'stripe_transfer_id'];

    protected $fillable = [
        'lease_id',
        'payer_user_id',
        'payee_user_id',
        'stripe_account_id',
        'gross_cents',
        'surcharge_cents',
        'application_fee_cents',
        'net_cents',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'stripe_transfer_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'gross_cents'           => 'integer',
            'surcharge_cents'       => 'integer',
            'application_fee_cents' => 'integer',
            'net_cents'             => 'integer',
            'paid_at'               => 'datetime',
        ]);
    }

    /**
     * The lease this payment settles. Cross-DB (DB 3) — resolved via the service
     * layer, never an Eloquent relationship.
     */
    public function getLease(): ?\App\Models\Lease\Lease
    {
        if (! $this->lease_id) {
            return null;
        }

        return app(\App\Services\Lease\LeaseService::class)->find($this->lease_id);
    }

    /**
     * The lessee who paid. Cross-DB (DB 1) — resolved via the service layer.
     */
    public function getPayer(): ?\App\Models\Identity\User
    {
        if (! $this->payer_user_id) {
            return null;
        }

        return app(\App\Services\Identity\UserService::class)->findById($this->payer_user_id);
    }

    /**
     * The landowner who was paid. Cross-DB (DB 1) — resolved via the service layer.
     */
    public function getPayee(): ?\App\Models\Identity\User
    {
        if (! $this->payee_user_id) {
            return null;
        }

        return app(\App\Services\Identity\UserService::class)->findById($this->payee_user_id);
    }

    /**
     * A human-readable label for the settled lease — "Property Title · start–end".
     * Falls back gracefully when the cross-DB lease or property can't be resolved.
     */
    public function leaseLabel(): ?string
    {
        $lease = $this->getLease();

        if (! $lease) {
            return null;
        }

        $title = $lease->getProperty()?->title ?? 'Lease';
        $start = $lease->start_date?->format('M j, Y');
        $end   = $lease->end_date?->format('M j, Y');

        $dates = $start && $end ? " · {$start} – {$end}" : '';

        return $title.$dates;
    }
}
