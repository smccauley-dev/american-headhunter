<?php

namespace App\Jobs\Billing;

use App\Models\Billing\Payment;
use App\Models\Billing\StripeAccount;
use App\Models\Billing\StripeInvoiceProjection;
use App\Models\Billing\Subscription;
use App\Services\Audit\AuditService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Routes a verified Stripe webhook event to the right billing-state update.
 * Runs on the `priority` queue. The controller has already verified the
 * signature; this job assumes the payload is trustworthy.
 *
 * @see \App\Http\Controllers\Api\StripeWebhookController
 */
class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /** Stripe status string => local subscriptions.status enum. */
    private const SUBSCRIPTION_STATUS_MAP = [
        'active'   => 'active',
        'trialing' => 'trialing',
        'past_due' => 'past_due',
        'canceled' => 'cancelled',
        'unpaid'   => 'unpaid',
    ];

    /**
     * @param array<string,mixed> $object The event's data.object payload.
     */
    public function __construct(
        private readonly string $eventId,
        private readonly string $eventType,
        private readonly array  $object,
    ) {
        $this->onQueue('priority');
    }

    public function handle(EntitlementService $entitlements, AuditService $audit, SubscriptionService $subscriptions, StripeService $stripe): void
    {
        // Stripe may deliver the same event more than once — process it once.
        if (! Cache::store('valkey')->add("stripe_webhook:{$this->eventId}", 1, now()->addDays(2))) {
            return;
        }

        match ($this->eventType) {
            'checkout.session.completed'    => $this->checkoutCompleted($subscriptions, $stripe),
            'customer.subscription.updated' => $this->subscriptionUpdated($entitlements, $audit),
            'customer.subscription.deleted' => $this->subscriptionDeleted($entitlements, $audit),
            'invoice.created',
            'invoice.finalized',
            'invoice.paid',
            'invoice.voided'                => $this->upsertInvoiceProjection($stripe),
            'invoice.payment_failed'        => $this->invoicePaymentFailed($entitlements, $audit, $stripe),
            'charge.refunded'               => $this->chargeRefunded(),
            'payment_intent.succeeded'      => $this->paymentIntentSucceeded($audit),
            'account.updated'               => $this->accountUpdated($audit),
            default                         => Log::info('StripeWebhook: unhandled event type', ['type' => $this->eventType]),
        };
    }

    /**
     * A hosted Checkout completed — create the local subscription locked to the
     * plan version carried in metadata. SubscriptionService::start audits and
     * invalidates entitlements. Period dates default to monthly here and are
     * reconciled by the following customer.subscription.updated event.
     */
    private function checkoutCompleted(SubscriptionService $subscriptions, StripeService $stripe): void
    {
        // A setup-mode Checkout means the member updated their card (dunning flow):
        // finish it by making the new method the default and retrying the open invoice.
        if (($this->object['mode'] ?? null) === 'setup') {
            $setupIntentId = $this->object['setup_intent'] ?? null;
            if ($setupIntentId) {
                $stripe->applyUpdatedPaymentMethod($setupIntentId);
            }
            return;
        }

        if (($this->object['mode'] ?? null) !== 'subscription') {
            return; // one-time payments are handled elsewhere
        }

        $stripeSubId   = $this->object['subscription'] ?? null;
        $stripeCustId  = $this->object['customer'] ?? null;
        $userId        = $this->object['metadata']['user_id'] ?? null;
        $planVersionId = $this->object['metadata']['plan_version_id'] ?? null;

        if (! $stripeSubId || ! $userId || ! $planVersionId) {
            Log::warning('StripeWebhook: checkout.session.completed missing fields', ['stripe_subscription_id' => $stripeSubId]);
            return;
        }

        // Idempotent: the subscription may already exist from a replay or a
        // racing customer.subscription.* event.
        if (Subscription::where('stripe_subscription_id', $stripeSubId)->exists()) {
            return;
        }

        try {
            $subscriptions->start($userId, $planVersionId, [
                'stripe_subscription_id' => $stripeSubId,
                'stripe_customer_id'     => $stripeCustId,
                'status'                 => 'active',
            ]);
        } catch (\RuntimeException $e) {
            // start() throws if the user already holds an active subscription —
            // treat as already-reconciled rather than failing the webhook.
            Log::info('StripeWebhook: checkout.session.completed skipped', ['error' => $e->getMessage(), 'user_id' => $userId]);
        }
    }

    private function subscriptionDeleted(EntitlementService $entitlements, AuditService $audit): void
    {
        $stripeSubId = $this->object['id'] ?? null;
        if (! $stripeSubId) {
            return;
        }

        $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $sub || $sub->status === 'cancelled') {
            return;
        }

        $old = $sub->status;
        $sub->status       = 'cancelled';
        $sub->cancelled_at = now();
        $sub->save();

        $entitlements->invalidateForUser($sub->user_id);

        $audit->log(
            eventType:      'subscription.cancelled',
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $sub->user_id,
            actionSummary:  'Subscription cancelled via Stripe webhook',
            oldValues:      ['status' => $old],
            newValues:      ['status' => 'cancelled'],
        );
    }

    private function subscriptionUpdated(EntitlementService $entitlements, AuditService $audit): void
    {
        $stripeSubId = $this->object['id'] ?? null;
        if (! $stripeSubId) {
            return;
        }

        $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $sub) {
            Log::info('StripeWebhook: no local subscription', ['stripe_subscription_id' => $stripeSubId]);
            return;
        }

        $old          = $sub->status;
        $stripeStatus = $this->object['status'] ?? null;

        if (isset(self::SUBSCRIPTION_STATUS_MAP[$stripeStatus])) {
            $sub->status = self::SUBSCRIPTION_STATUS_MAP[$stripeStatus];
        }
        if (! empty($this->object['current_period_start'])) {
            $sub->current_period_start = Carbon::createFromTimestamp($this->object['current_period_start']);
        }
        if (! empty($this->object['current_period_end'])) {
            $sub->current_period_end = Carbon::createFromTimestamp($this->object['current_period_end']);
        }

        // A scheduled cancel (cancel_at_period_end) keeps the subscription active
        // until the period ends; we record the date so the member sees "Cancels …".
        // Clearing the flag (Resume) wipes that date again.
        if (! empty($this->object['cancel_at_period_end'])) {
            $cancelAt = $this->object['cancel_at'] ?? $this->object['current_period_end'] ?? null;
            if ($cancelAt && empty($sub->cancelled_at)) {
                $sub->cancelled_at = Carbon::createFromTimestamp($cancelAt);
            }
        } elseif ($sub->status !== 'cancelled') {
            $sub->cancelled_at = null;
        }

        if ($sub->status === 'cancelled' && empty($sub->cancelled_at)) {
            $sub->cancelled_at = now();
        }

        // Reconcile the locked plan version from Stripe metadata. The change-plan
        // controller swaps this locally and refreshes the Stripe metadata, so this
        // is normally a no-op; it keeps Stripe authoritative if that swap was missed.
        $metaVersionId = $this->object['metadata']['plan_version_id'] ?? null;
        if ($metaVersionId && $metaVersionId !== $sub->plan_version_id) {
            $sub->plan_version_id = $metaVersionId;
        }

        $sub->save();

        $entitlements->invalidateForUser($sub->user_id);

        $audit->log(
            eventType:      'subscription.updated',
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $sub->user_id,
            actionSummary:  "Subscription status {$old} → {$sub->status} via Stripe webhook",
            oldValues:      ['status' => $old],
            newValues:      ['status' => $sub->status],
        );
    }

    private function invoicePaymentFailed(EntitlementService $entitlements, AuditService $audit, StripeService $stripe): void
    {
        // Mirror the (unpaid) invoice locally regardless of how the subscription
        // resolves below.
        $this->upsertInvoiceProjection($stripe);

        $stripeSubId = $this->invoiceSubscriptionDetails()['subscription'] ?? null;
        if (! $stripeSubId) {
            Log::info('StripeWebhook: invoice.payment_failed without subscription');
            return;
        }

        $sub = Subscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (! $sub) {
            return;
        }

        $old         = $sub->status;
        $sub->status = 'past_due';
        $sub->save();

        $entitlements->invalidateForUser($sub->user_id);

        $audit->log(
            eventType:      'subscription.past_due',
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $sub->user_id,
            actionSummary:  'Invoice payment failed — subscription marked past_due',
            oldValues:      ['status' => $old],
            newValues:      ['status' => 'past_due'],
        );
        // User notification + retry scheduling land with Phase 5.4.
    }

    private function paymentIntentSucceeded(AuditService $audit): void
    {
        $intentId = $this->object['id'] ?? null;
        if (! $intentId) {
            return;
        }

        $payment = Payment::where('stripe_payment_intent_id', $intentId)->first();
        if (! $payment) {
            Log::info('StripeWebhook: no local payment for intent', ['payment_intent' => $intentId]);
            return;
        }
        if ($payment->status === 'succeeded') {
            return;
        }

        $payment->status = 'succeeded';
        $charge          = $this->object['latest_charge'] ?? null;
        if ($charge) {
            $payment->stripe_charge_id = is_array($charge) ? ($charge['id'] ?? null) : $charge;
        }
        $payment->save();

        $audit->log(
            eventType:      'payment.succeeded',
            sourceDatabase: 'ah_billing',
            tableName:      'payments',
            recordId:       $payment->id,
            userId:         $payment->payer_user_id,
            actionSummary:  'Payment succeeded via Stripe webhook',
            newValues:      ['status' => 'succeeded'],
        );
    }

    private function accountUpdated(AuditService $audit): void
    {
        $acctId = $this->object['id'] ?? null;
        if (! $acctId) {
            return;
        }

        $account = StripeAccount::where('stripe_account_id', $acctId)->first();
        if (! $account) {
            Log::info('StripeWebhook: no local stripe_account', ['stripe_account_id' => $acctId]);
            return;
        }

        $account->charges_enabled   = (bool) ($this->object['charges_enabled'] ?? $account->charges_enabled);
        $account->payouts_enabled   = (bool) ($this->object['payouts_enabled'] ?? $account->payouts_enabled);
        $account->details_submitted = (bool) ($this->object['details_submitted'] ?? $account->details_submitted);
        if ($account->charges_enabled && $account->payouts_enabled && empty($account->onboarding_completed_at)) {
            $account->onboarding_completed_at = now();
        }
        $account->save();

        $audit->log(
            eventType:      'stripe_account.updated',
            sourceDatabase: 'ah_billing',
            tableName:      'stripe_accounts',
            recordId:       $account->id,
            userId:         $account->user_id,
            actionSummary:  'Stripe Connect account updated via webhook',
            newValues:      [
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
            ],
        );
    }

    /**
     * Read the subscription metadata Stripe nests on a subscription invoice. The
     * dahlia API moved the subscription id and our checkout metadata off the
     * top-level invoice onto invoice.parent.subscription_details.
     *
     * @return array<string,mixed>|null null when the invoice isn't subscription-backed
     */
    private function invoiceSubscriptionDetails(): ?array
    {
        $details = $this->object['parent']['subscription_details'] ?? null;

        return is_array($details) ? $details : null;
    }

    /**
     * Upsert the local projection of a Stripe SUBSCRIPTION invoice (Phase 5.7) so
     * the admin invoice list and member billing history can read from DB 4 instead
     * of calling Stripe per render. Only subscription invoices are mirrored — the
     * subscriber + plan version ride in parent.subscription_details.metadata. On a
     * paid invoice we also capture the PaymentIntent (the only point it exists) so
     * a later charge.refunded can map back to this row. Stripe stays the source of
     * truth; the daily reconcile job is the backstop for any event we miss.
     */
    private function upsertInvoiceProjection(StripeService $stripe): void
    {
        $invoiceId = $this->object['id'] ?? null;
        if (! $invoiceId) {
            return;
        }

        $details = $this->invoiceSubscriptionDetails();
        if (! $details) {
            return; // a one-off invoice — out of scope for the subscription projection
        }

        $stripeSubId      = $details['subscription'] ?? null;
        $subscriberUserId = $details['metadata']['user_id'] ?? null;

        // Fall back to the local subscription if Stripe didn't echo our metadata.
        if (! $subscriberUserId && $stripeSubId) {
            $subscriberUserId = Subscription::where('stripe_subscription_id', $stripeSubId)->value('user_id');
        }
        if (! $subscriberUserId) {
            Log::info('StripeWebhook: invoice projection skipped — no subscriber', ['invoice' => $invoiceId]);
            return;
        }

        $status = $this->object['status'] ?? 'draft';

        $attributes = [
            'subscriber_user_id'     => $subscriberUserId,
            'stripe_subscription_id' => $stripeSubId,
            'stripe_customer_id'     => $this->object['customer'] ?? null,
            'number'                 => $this->object['number'] ?? null,
            'status'                 => $status,
            'amount_cents'           => (int) (($this->object['amount_paid'] ?? 0) ?: ($this->object['amount_due'] ?? 0)),
            'currency'               => strtoupper((string) ($this->object['currency'] ?? 'usd')),
            'period_start'           => $this->timestampOrNull($this->object['period_start'] ?? null),
            'period_end'             => $this->timestampOrNull($this->object['period_end'] ?? null),
            'hosted_invoice_url'     => $this->object['hosted_invoice_url'] ?? null,
            'invoice_pdf'            => $this->object['invoice_pdf'] ?? null,
            'stripe_created_at'      => $this->timestampOrNull($this->object['created'] ?? null),
        ];

        // Best-effort PI capture once the invoice is paid — a Stripe hiccup here
        // must not fail the projection write (the reconcile job will backfill it).
        if ($status === 'paid') {
            try {
                $pi = $stripe->invoicePaymentIntentIdFor($invoiceId);
                if ($pi) {
                    $attributes['stripe_payment_intent_id'] = $pi;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        StripeInvoiceProjection::updateOrCreate(
            ['stripe_invoice_id' => $invoiceId],
            $attributes,
        );
    }

    /**
     * Reflect a refund on the projected invoice. The dahlia API strips the invoice
     * reference from both the charge and its PaymentIntent, so we map by the PI we
     * captured on the row at payment time.
     */
    private function chargeRefunded(): void
    {
        $paymentIntent = $this->object['payment_intent'] ?? null;
        if (! $paymentIntent) {
            return;
        }

        $projection = StripeInvoiceProjection::where('stripe_payment_intent_id', $paymentIntent)->first();
        if (! $projection) {
            Log::info('StripeWebhook: charge.refunded has no projected invoice', ['payment_intent' => $paymentIntent]);
            return;
        }

        $charged  = (int) ($this->object['amount'] ?? 0);
        $refunded = (int) ($this->object['amount_refunded'] ?? 0);

        $projection->amount_refunded_cents = $refunded;
        $projection->refund_status = $refunded <= 0
            ? 'none'
            : ($charged > 0 && $refunded >= $charged ? 'full' : 'partial');
        $projection->save();
    }

    private function timestampOrNull(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
