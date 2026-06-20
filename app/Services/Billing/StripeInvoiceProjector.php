<?php

namespace App\Services\Billing;

use App\Models\Billing\StripeInvoiceProjection;
use App\Models\Billing\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Maps a Stripe SUBSCRIPTION invoice payload onto the local projection (Phase 5.7)
 * and upserts it. Shared by the webhook (live, per-event) and the reconcile job
 * (daily sweep) so the dahlia-specific field paths live in exactly one place.
 *
 * The payload shape is identical from both callers: the webhook passes the raw
 * `$event->data->object`; the reconcile job passes `$invoice->toArray()`. Under
 * the 2026-05-27.dahlia API the invoice has NO top-level `subscription` — the
 * subscription id and our checkout metadata live under
 * `parent.subscription_details`.
 */
class StripeInvoiceProjector
{
    /**
     * Upsert one subscription invoice into the projection. Returns the row, or null
     * when the invoice isn't subscription-backed or can't be attributed to a user.
     *
     * @param array<string,mixed>                          $invoice         Stripe invoice payload
     * @param string|null                                  $paymentIntentId captured PI (paid invoices) — stored so charge.refunded can map back
     * @param array{refunded_cents:int,charged_cents:int}|null $refund      authoritative refund totals (reconcile only); null leaves refund fields untouched
     */
    public function upsert(array $invoice, ?string $paymentIntentId = null, ?array $refund = null): ?StripeInvoiceProjection
    {
        $invoiceId = $invoice['id'] ?? null;
        if (! $invoiceId) {
            return null;
        }

        $details = $invoice['parent']['subscription_details'] ?? null;
        if (! is_array($details)) {
            return null; // a one-off invoice — out of scope for the subscription projection
        }

        $stripeSubId      = $details['subscription'] ?? null;
        $subscriberUserId = $details['metadata']['user_id'] ?? null;

        // Fall back to the local subscription if Stripe didn't echo our metadata.
        if (! $subscriberUserId && $stripeSubId) {
            $subscriberUserId = Subscription::where('stripe_subscription_id', $stripeSubId)->value('user_id');
        }
        if (! $subscriberUserId) {
            Log::info('StripeInvoiceProjector: skipped — no subscriber', ['invoice' => $invoiceId]);
            return null;
        }

        $attributes = [
            'subscriber_user_id'     => $subscriberUserId,
            'stripe_subscription_id' => $stripeSubId,
            'stripe_customer_id'     => $invoice['customer'] ?? null,
            'number'                 => $invoice['number'] ?? null,
            'status'                 => $invoice['status'] ?? 'draft',
            'amount_cents'           => (int) (($invoice['amount_paid'] ?? 0) ?: ($invoice['amount_due'] ?? 0)),
            'currency'               => strtoupper((string) ($invoice['currency'] ?? 'usd')),
            'period_start'           => $this->timestampOrNull($invoice['period_start'] ?? null),
            'period_end'             => $this->timestampOrNull($invoice['period_end'] ?? null),
            'hosted_invoice_url'     => $invoice['hosted_invoice_url'] ?? null,
            'invoice_pdf'            => $invoice['invoice_pdf'] ?? null,
            'stripe_created_at'      => $this->timestampOrNull($invoice['created'] ?? null),
        ];

        if ($paymentIntentId) {
            $attributes['stripe_payment_intent_id'] = $paymentIntentId;
        }

        if ($refund !== null) {
            $refunded = max(0, $refund['refunded_cents']);
            $charged  = $refund['charged_cents'];
            $attributes['amount_refunded_cents'] = $refunded;
            $attributes['refund_status'] = $refunded <= 0
                ? 'none'
                : ($charged > 0 && $refunded >= $charged ? 'full' : 'partial');
        }

        return StripeInvoiceProjection::updateOrCreate(
            ['stripe_invoice_id' => $invoiceId],
            $attributes,
        );
    }

    private function timestampOrNull(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
