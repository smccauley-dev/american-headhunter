<?php

namespace App\Jobs\Billing;

use App\Models\Billing\Payment;
use App\Models\Billing\StripeAccount;
use App\Models\Billing\Subscription;
use App\Services\Audit\AuditService;
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

    public function handle(EntitlementService $entitlements, AuditService $audit): void
    {
        // Stripe may deliver the same event more than once — process it once.
        if (! Cache::store('valkey')->add("stripe_webhook:{$this->eventId}", 1, now()->addDays(2))) {
            return;
        }

        match ($this->eventType) {
            'customer.subscription.updated' => $this->subscriptionUpdated($entitlements, $audit),
            'invoice.payment_failed'        => $this->invoicePaymentFailed($entitlements, $audit),
            'payment_intent.succeeded'      => $this->paymentIntentSucceeded($audit),
            'account.updated'               => $this->accountUpdated($audit),
            default                         => Log::info('StripeWebhook: unhandled event type', ['type' => $this->eventType]),
        };
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
        if ($sub->status === 'cancelled' && empty($sub->cancelled_at)) {
            $sub->cancelled_at = now();
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

    private function invoicePaymentFailed(EntitlementService $entitlements, AuditService $audit): void
    {
        $stripeSubId = $this->object['subscription'] ?? null;
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
}
