<?php

namespace App\Models\Billing;

use App\Models\BaseModelWithSoftDeletes;

/**
 * Phase 5.7 — local read model of a Stripe subscription invoice. Written only by
 * the trusted ah_system path (webhook worker + reconcile job); read under
 * ah_runtime scoped to the subscriber + staff by RLS. Stripe is the source of
 * truth, so these rows are a denormalized mirror, never an authority.
 */
class StripeInvoiceProjection extends BaseModelWithSoftDeletes
{
    protected $connection = 'billing';
    protected $table      = 'stripe_invoice_projections';

    protected $fillable = [
        'subscriber_user_id',
        'stripe_invoice_id',
        'stripe_subscription_id',
        'stripe_customer_id',
        'number',
        'status',
        'amount_cents',
        'amount_refunded_cents',
        'currency',
        'refund_status',
        'period_start',
        'period_end',
        'hosted_invoice_url',
        'invoice_pdf',
        'stripe_created_at',
    ];

    // Stripe identifiers never need to reach the client (the view uses the hosted
    // URL / PDF link); keep them out of serialization and logs.
    protected $hidden = [
        'stripe_invoice_id',
        'stripe_subscription_id',
        'stripe_customer_id',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'amount_cents'          => 'integer',
            'amount_refunded_cents' => 'integer',
            'period_start'          => 'datetime',
            'period_end'            => 'datetime',
            'stripe_created_at'     => 'datetime',
        ]);
    }
}
