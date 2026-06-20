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
        'stripe_payment_intent_id',
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
        'stripe_payment_intent_id',
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

    /**
     * A subscriber's invoices for display, newest first — the projection-backed
     * read that replaces live StripeService::listInvoices() (Phase 5.7). Returns
     * the identical row shape so the admin invoice list and the member billing
     * history render unchanged.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function displayForUser(string $userId, int $limit = 24): array
    {
        return static::query()
            ->where('subscriber_user_id', $userId)
            ->orderByDesc('stripe_created_at')
            ->limit($limit)
            ->get()
            ->map->toDisplayArray()
            ->all();
    }

    /**
     * One invoice shaped exactly like a StripeService::listInvoices() row so the
     * existing views (membership-invoices.blade, Hunter.tsx billing history) need
     * no changes when the data source flips from Stripe to the projection.
     *
     * @return array<string,mixed>
     */
    public function toDisplayArray(): array
    {
        $amountCents   = (int) $this->amount_cents;
        $refundedCents = (int) $this->amount_refunded_cents;

        return [
            'id'             => $this->stripe_invoice_id,
            'number'         => $this->number,
            'date'           => $this->stripe_created_at?->format('M j, Y'),
            'amount'         => number_format($amountCents / 100, 2),
            'amount_cents'   => $amountCents,
            'currency'       => strtoupper((string) $this->currency),
            'status'         => $this->status, // paid | open | void | draft | uncollectible
            'refund_status'  => $this->refund_status ?? 'none', // none | partial | full
            'refunded'       => number_format($refundedCents / 100, 2),
            'refunded_cents' => $refundedCents,
            'hosted_url'     => $this->hosted_invoice_url,
            'pdf_url'        => $this->invoice_pdf,
        ];
    }
}
