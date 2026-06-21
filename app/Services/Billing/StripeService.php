<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Coupon;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\Price;
use Stripe\Product;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe SDK. The secret key is read from
 * config('services.stripe.secret') — never hardcoded.
 *
 * Methods are intentionally side-effect-light on our own DB: the Stripe API
 * calls return identifiers, and callers (StripeSyncPlans, CheckoutController,
 * ProcessStripeWebhook) own the local persistence. The one exception is
 * getOrCreateCustomer, which reads prior subscriptions to avoid duplicate
 * Stripe customers.
 */
class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));
    }

    /**
     * Verify a raw webhook payload against the Stripe-Signature header and
     * return the parsed event.
     *
     * @throws \UnexpectedValueException                       on malformed payload
     * @throws \Stripe\Exception\SignatureVerificationException on signature mismatch
     */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            (string) config('services.stripe.webhook_secret'),
        );
    }

    /**
     * Create or update the Stripe Product mirroring a membership plan. Returns
     * the Stripe product id. Idempotent: updates the named product when the plan
     * already carries a stripe_product_id, otherwise creates a new one.
     */
    public function upsertProduct(MembershipPlan $plan): string
    {
        if ($plan->stripe_product_id) {
            Product::update($plan->stripe_product_id, [
                'name'     => $plan->display_name,
                'metadata' => ['plan_key' => $plan->plan_key],
            ]);

            return $plan->stripe_product_id;
        }

        $product = Product::create([
            'name'     => $plan->display_name,
            'metadata' => ['plan_key' => $plan->plan_key],
        ]);

        return $product->id;
    }

    /**
     * Create a recurring Stripe Price under a product. Prices are immutable in
     * Stripe (matching our immutable plan_versions), so a new price is created
     * whenever pricing changes; callers store the returned id.
     *
     * @param 'monthly'|'annual' $interval
     */
    public function createPrice(string $productId, int $unitAmountCents, string $interval, string $currency = 'USD'): string
    {
        $price = Price::create([
            'product'     => $productId,
            'unit_amount' => $unitAmountCents,
            'currency'    => strtolower($currency),
            'recurring'   => ['interval' => $interval === 'annual' ? 'year' : 'month'],
        ]);

        return $price->id;
    }

    /**
     * Resolve the Stripe customer id for a user, reusing the customer from any
     * prior subscription so resubscribing doesn't create duplicates. Creates a
     * new Stripe customer when none exists yet.
     */
    public function getOrCreateCustomer(User $user): string
    {
        $existing = Subscription::query()
            ->where('user_id', $user->id)
            ->whereNotNull('stripe_customer_id')
            ->latest('created_at')
            ->value('stripe_customer_id');

        if ($existing) {
            return $existing;
        }

        $customer = Customer::create([
            'email'    => $user->email,
            'metadata' => ['user_id' => $user->id],
        ]);

        return $customer->id;
    }

    /**
     * Create a hosted Checkout Session for a new subscription. The price comes
     * from the plan's current Stripe price (grandfathering is enforced by Stripe
     * holding the original price on existing subscriptions). The locked
     * plan_version_id rides in metadata so the webhook records it verbatim.
     *
     * A promo code's discount is applied by passing the synced Stripe Coupon id
     * (run stripe:sync-promos) — hosted Checkout has no other way to discount a
     * subscription. The redemption metadata (promo_code_id / promotional_period_id)
     * rides in both metadata bags so the webhook can record the claim.
     *
     * @param 'monthly'|'annual' $interval
     * @param array<string,string> $extraMetadata
     */
    public function createSubscriptionCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $planVersionId,
        string $interval,
        string $successUrl,
        string $cancelUrl,
        ?string $couponId = null,
        array $extraMetadata = [],
    ): Session {
        $priceId = $interval === 'annual'
            ? $plan->stripe_annual_price_id
            : $plan->stripe_monthly_price_id;

        if (! $priceId) {
            throw new \RuntimeException("Plan {$plan->plan_key} has no Stripe price for interval '{$interval}'. Run stripe:sync-plans.");
        }

        $params = [
            'mode'                => 'subscription',
            'customer'            => $this->getOrCreateCustomer($user),
            'client_reference_id' => $user->id,
            'line_items'          => [['price' => $priceId, 'quantity' => 1]],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'metadata'            => array_merge([
                'user_id'         => $user->id,
                'plan_version_id' => $planVersionId,
                'plan_key'        => $plan->plan_key,
            ], $extraMetadata),
            'subscription_data'   => [
                'metadata' => array_merge([
                    'user_id'         => $user->id,
                    'plan_version_id' => $planVersionId,
                ], $extraMetadata),
            ],
        ];

        if ($couponId) {
            $params['discounts'] = [['coupon' => $couponId]];
        }

        return Session::create($params);
    }

    /**
     * Create (or reuse) a Stripe Coupon mirroring a promotional period's monetary
     * discount, so it can be applied at hosted Checkout. Returns the coupon id, or
     * null for promos with no monetary discount (e.g. tier_grant). Idempotent:
     * reuses the period's stored stripe_coupon_id; the caller persists a new id.
     */
    public function upsertCoupon(PromotionalPeriod $promo): ?string
    {
        if ($promo->stripe_coupon_id) {
            return $promo->stripe_coupon_id;
        }

        $params = ['name' => $promo->display_name];

        if ($promo->discount_percentage && $promo->discount_percentage > 0) {
            $params['percent_off'] = (float) $promo->discount_percentage;
        } elseif ($promo->discount_amount_cents && $promo->discount_amount_cents > 0) {
            $params['amount_off'] = (int) $promo->discount_amount_cents;
            $params['currency']   = 'usd';
        } else {
            return null; // nothing monetary to mirror
        }

        // A fixed-day promo repeats over whole months; no duration means once.
        if ($promo->duration_days) {
            $params['duration']           = 'repeating';
            $params['duration_in_months'] = max(1, (int) round($promo->duration_days / 30));
        } else {
            $params['duration'] = 'once';
        }

        return Coupon::create($params)->id;
    }

    /**
     * Schedule a subscription to cancel at the end of the current paid period
     * (the member keeps access through what they've already paid for). Stripe
     * then fires customer.subscription.updated now and .deleted at period end.
     * Returns the updated Stripe subscription so the caller can read cancel_at.
     */
    public function cancelSubscriptionAtPeriodEnd(string $stripeSubscriptionId): StripeSubscription
    {
        return StripeSubscription::update($stripeSubscriptionId, ['cancel_at_period_end' => true]);
    }

    /**
     * Undo a scheduled cancellation — the subscription keeps renewing.
     */
    public function resumeSubscription(string $stripeSubscriptionId): StripeSubscription
    {
        return StripeSubscription::update($stripeSubscriptionId, ['cancel_at_period_end' => false]);
    }

    /**
     * List a customer's invoices, shaped for display. Each row links to Stripe's
     * hosted invoice page and PDF — Stripe stays the source of truth and hosts
     * the documents, so nothing is stored locally.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listInvoices(string $customerId, int $limit = 24): array
    {
        // Expand payments so each invoice exposes its PaymentIntent id — recent
        // Stripe API versions dropped the top-level invoice.charge/payment_intent.
        $invoices = Invoice::all([
            'customer' => $customerId,
            'limit'    => $limit,
            'expand'   => ['data.payments'],
        ]);

        // Refund totals keyed by PaymentIntent id, fetched in a single charges
        // call (charges carry amount_refunded + payment_intent) so the refund
        // column costs one extra request instead of one per invoice. If it fails,
        // the column degrades to "—" and the invoice list still renders.
        $refundedByPi = [];
        try {
            $charges = Charge::all(['customer' => $customerId, 'limit' => 100]);
            foreach ($charges->data as $charge) {
                $pi = $charge->payment_intent ?? null;
                if ($pi) {
                    $refundedByPi[$pi] = ($refundedByPi[$pi] ?? 0) + (int) ($charge->amount_refunded ?? 0);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return collect($invoices->data)->map(function (Invoice $inv) use ($refundedByPi) {
            $amountCents = (int) (($inv->amount_paid ?: $inv->amount_due) ?? 0);

            $piId = $this->invoicePaymentIntentId($inv);
            $refundedCents = $piId !== null ? ($refundedByPi[$piId] ?? 0) : 0;
            $refundStatus  = $refundedCents <= 0
                ? 'none'
                : ($amountCents > 0 && $refundedCents >= $amountCents ? 'full' : 'partial');

            return [
                'id'             => $inv->id,
                'number'         => $inv->number,
                'date'           => $inv->created ? date('M j, Y', $inv->created) : null,
                'amount'         => number_format($amountCents / 100, 2),
                'amount_cents'   => $amountCents,
                'currency'       => strtoupper((string) $inv->currency),
                'status'         => $inv->status, // paid | open | void | draft | uncollectible
                'refund_status'  => $refundStatus, // none | partial | full
                'refunded'       => number_format($refundedCents / 100, 2),
                'refunded_cents' => $refundedCents,
                'hosted_url'     => $inv->hosted_invoice_url,
                'pdf_url'        => $inv->invoice_pdf,
            ];
        })->all();
    }

    /**
     * Reconcile the invoice projection (Phase 5.7) — the daily backstop for any
     * missed or out-of-order webhook. Re-pulls subscription invoices created in
     * the lookback window and upserts them via the shared projector; refund totals
     * are read authoritatively from the matching charges (cumulative
     * amount_refunded), so this also self-heals a missed charge.refunded and
     * backfills stripe_payment_intent_id. Returns the number of rows upserted.
     */
    public function reconcileInvoiceProjections(StripeInvoiceProjector $projector, int $lookbackDays = 45): int
    {
        $cutoff = now()->subDays($lookbackDays)->timestamp;

        // Refund + charged totals keyed by PaymentIntent for the window (one
        // paginated sweep). amount_refunded on a charge is cumulative, so a charge
        // in-window always reports the authoritative refunded total.
        $refundedByPi = [];
        $chargedByPi  = [];
        foreach (Charge::all(['created' => ['gte' => $cutoff], 'limit' => 100])->autoPagingIterator() as $charge) {
            $pi = $charge->payment_intent ?? null;
            if (! $pi) {
                continue;
            }
            $refundedByPi[$pi] = ($refundedByPi[$pi] ?? 0) + (int) ($charge->amount_refunded ?? 0);
            $chargedByPi[$pi]  = ($chargedByPi[$pi] ?? 0) + (int) ($charge->amount ?? 0);
        }

        $count = 0;
        $invoices = Invoice::all([
            'created' => ['gte' => $cutoff],
            'limit'   => 100,
            'expand'  => ['data.payments'],
        ]);

        foreach ($invoices->autoPagingIterator() as $invoice) {
            $payload = $invoice->toArray();
            if (! isset($payload['parent']['subscription_details'])) {
                continue; // subscription invoices only
            }

            $pi = $this->invoicePaymentIntentId($invoice);

            // Only pass refund totals when the backing charge is in-window (and
            // thus authoritative); otherwise leave whatever the webhook set.
            $refund = ($pi !== null && isset($refundedByPi[$pi]))
                ? ['refunded_cents' => $refundedByPi[$pi], 'charged_cents' => $chargedByPi[$pi] ?? 0]
                : null;

            if ($projector->upsert($payload, $pi, $refund)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Retrieve an invoice from Stripe and resolve its PaymentIntent id. The
     * webhook projection (Phase 5.7) calls this at invoice.paid time to capture
     * the PI on the local row, so a later charge.refunded — which carries no
     * invoice back-reference under the dahlia API — can be mapped to the invoice.
     */
    public function invoicePaymentIntentIdFor(string $invoiceId): ?string
    {
        $invoice = Invoice::retrieve(['id' => $invoiceId, 'expand' => ['payments']]);

        return $this->invoicePaymentIntentId($invoice);
    }

    /**
     * Resolve the PaymentIntent id backing an invoice. Recent API versions moved
     * the payment off the top-level invoice.payment_intent/charge fields onto the
     * invoice.payments list; this reads that first, then falls back to the legacy
     * fields. Returns null when the invoice has no captured payment yet.
     */
    private function invoicePaymentIntentId(Invoice $invoice): ?string
    {
        foreach (($invoice->payments->data ?? []) as $payment) {
            $candidate = $payment->payment->payment_intent ?? null;
            if ($candidate) {
                return $candidate;
            }
        }

        return ! empty($invoice->payment_intent) ? (string) $invoice->payment_intent : null;
    }

    /**
     * Refund a paid invoice through Stripe (the source of truth — there is no
     * local invoices table). The invoice's PaymentIntent (or legacy charge) is
     * refunded; a null amount refunds the full remaining balance, a cents amount
     * issues a partial refund. The optional reason maps to Stripe's enum and an
     * optional note rides in refund metadata. Returns the Stripe Refund.
     *
     * @param 'duplicate'|'fraudulent'|'requested_by_customer'|null $reason
     * @throws \RuntimeException when the invoice has no captured payment to refund
     */
    public function refundInvoice(string $invoiceId, ?int $amountCents = null, ?string $reason = null, ?string $note = null): Refund
    {
        $invoice = Invoice::retrieve(['id' => $invoiceId, 'expand' => ['payments']]);

        $paymentIntent = $this->invoicePaymentIntentId($invoice);

        $params = [];
        if ($paymentIntent) {
            $params['payment_intent'] = $paymentIntent;
        } elseif (! empty($invoice->charge)) {
            $params['charge'] = $invoice->charge;
        } else {
            throw new \RuntimeException("Invoice {$invoice->number} has no captured payment to refund.");
        }

        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }
        if ($reason !== null && $reason !== '') {
            $params['reason'] = $reason; // duplicate | fraudulent | requested_by_customer
        }

        $metadata = ['invoice_id' => $invoiceId];
        if ($note !== null && $note !== '') {
            $metadata['note'] = $note;
        }
        $params['metadata'] = $metadata;

        return Refund::create($params);
    }

    /**
     * Create a one-time hosted Checkout Session (mode=payment) to capture a
     * refundable security deposit. There is no recurring plan, so the amount is
     * passed inline via price_data. The lease + party references ride in metadata
     * (mirrored onto the PaymentIntent) so the webhook can author the held deposit
     * row — no local write happens on the runtime path.
     *
     * @param array<string,string> $metadata
     */
    public function createDepositCheckoutSession(User $payer, int $amountCents, array $metadata, string $successUrl, string $cancelUrl): Session
    {
        return Session::create([
            'mode'                => 'payment',
            'customer'            => $this->getOrCreateCustomer($payer),
            'client_reference_id' => $payer->id,
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $amountCents,
                    'product_data' => ['name' => 'Refundable security deposit'],
                ],
            ]],
            'payment_intent_data' => ['metadata' => $metadata],
            'metadata'            => $metadata,
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ]);
    }

    /**
     * Refund a PaymentIntent directly — used to return a security deposit, which
     * is a one-time charge rather than an invoice. A null amount refunds the full
     * remaining balance; a cents amount issues a partial refund. An optional note
     * rides in refund metadata. Returns the Stripe Refund.
     */
    public function refundPaymentIntent(string $paymentIntentId, ?int $amountCents = null, ?string $note = null): Refund
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }
        if ($note !== null && $note !== '') {
            $params['metadata'] = ['note' => $note];
        }

        return Refund::create($params);
    }

    /**
     * Switch an existing subscription to a different plan, immediately and with
     * proration (Stripe credits unused time on the old price and charges the
     * difference). The billing interval is preserved: we read the current item's
     * recurring interval from Stripe (the subscriptions table stores no interval)
     * and pick the new plan's matching price. The locked plan_version_id is
     * refreshed in Stripe metadata so the webhook can reconcile.
     */
    public function changeSubscriptionPlan(string $stripeSubscriptionId, MembershipPlan $newPlan, string $newPlanVersionId): StripeSubscription
    {
        $sub  = StripeSubscription::retrieve($stripeSubscriptionId);
        $item = $sub->items->data[0] ?? null;
        if (! $item) {
            throw new \RuntimeException("Stripe subscription {$stripeSubscriptionId} has no line item to change.");
        }

        $interval = ($item->price->recurring->interval ?? 'month') === 'year' ? 'annual' : 'monthly';
        $priceId  = $interval === 'annual'
            ? $newPlan->stripe_annual_price_id
            : $newPlan->stripe_monthly_price_id;

        if (! $priceId) {
            throw new \RuntimeException("Plan {$newPlan->plan_key} has no Stripe price for interval '{$interval}'. Run stripe:sync-plans.");
        }

        return StripeSubscription::update($stripeSubscriptionId, [
            'items'              => [['id' => $item->id, 'price' => $priceId]],
            'proration_behavior' => 'create_prorations',
            'metadata'           => [
                'user_id'         => $sub->metadata->user_id ?? null,
                'plan_version_id' => $newPlanVersionId,
            ],
        ]);
    }

    /**
     * Read a subscription's billing interval and current period window from
     * Stripe — the authoritative source for both. Under the dahlia API the period
     * fields live on the subscription item, so we read those first and fall back
     * to the (legacy) top-level fields. Used by the webhook to record the real
     * renew date and interval instead of guessing them locally.
     *
     * @return array{interval:string, current_period_start:\Carbon\Carbon, current_period_end:\Carbon\Carbon}
     */
    public function subscriptionPeriod(string $stripeSubscriptionId): array
    {
        $sub  = StripeSubscription::retrieve($stripeSubscriptionId);
        $item = $sub->items->data[0] ?? null;

        $interval = (($item->price->recurring->interval ?? 'month') === 'year') ? 'annual' : 'monthly';

        $start = $item->current_period_start ?? $sub->current_period_start ?? null;
        $end   = $item->current_period_end   ?? $sub->current_period_end   ?? null;

        return [
            'interval'             => $interval,
            'current_period_start' => $start ? \Carbon\Carbon::createFromTimestamp($start) : now(),
            'current_period_end'   => $end ? \Carbon\Carbon::createFromTimestamp($end) : now()->addMonth(),
        ];
    }

    /**
     * Create a hosted Checkout Session (mode=setup) to collect a new payment
     * method for an existing customer — used by the dunning flow when a payment
     * has failed. The card is captured by Stripe (never touches our server); the
     * resulting setup_intent is finished in the webhook via applyUpdatedPaymentMethod.
     */
    public function createPaymentUpdateCheckoutSession(User $user, string $successUrl, string $cancelUrl): Session
    {
        return Session::create([
            'mode'              => 'setup',
            'customer'          => $this->getOrCreateCustomer($user),
            'success_url'       => $successUrl,
            'cancel_url'        => $cancelUrl,
            'setup_intent_data' => [
                'metadata' => ['user_id' => $user->id],
            ],
        ]);
    }

    /**
     * Finish a card-update setup: make the newly collected payment method the
     * customer's default and the subscription's default, then retry the open
     * invoice so a past_due subscription recovers. Called from the webhook after
     * a setup-mode Checkout completes.
     */
    public function applyUpdatedPaymentMethod(string $setupIntentId): void
    {
        $intent          = SetupIntent::retrieve($setupIntentId);
        $paymentMethodId = $intent->payment_method;
        $customerId      = $intent->customer;

        if (! $paymentMethodId || ! $customerId) {
            return;
        }

        Customer::update((string) $customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        // Point each of the customer's subscriptions at the new default, then pay
        // any open invoice so Stripe re-attempts the charge and fires the
        // status-flipping webhooks.
        $subscriptions = StripeSubscription::all(['customer' => $customerId, 'limit' => 10]);
        foreach ($subscriptions->data as $sub) {
            StripeSubscription::update($sub->id, ['default_payment_method' => $paymentMethodId]);
        }

        $openInvoices = Invoice::all(['customer' => $customerId, 'status' => 'open', 'limit' => 1]);
        $invoice      = $openInvoices->data[0] ?? null;
        if ($invoice) {
            $invoice->pay();
        }
    }
}
