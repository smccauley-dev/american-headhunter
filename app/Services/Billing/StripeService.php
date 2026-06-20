<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use Stripe\Checkout\Session;
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
     * @param 'monthly'|'annual' $interval
     */
    public function createSubscriptionCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $planVersionId,
        string $interval,
        string $successUrl,
        string $cancelUrl,
    ): Session {
        $priceId = $interval === 'annual'
            ? $plan->stripe_annual_price_id
            : $plan->stripe_monthly_price_id;

        if (! $priceId) {
            throw new \RuntimeException("Plan {$plan->plan_key} has no Stripe price for interval '{$interval}'. Run stripe:sync-plans.");
        }

        return Session::create([
            'mode'                => 'subscription',
            'customer'            => $this->getOrCreateCustomer($user),
            'client_reference_id' => $user->id,
            'line_items'          => [['price' => $priceId, 'quantity' => 1]],
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
            'metadata'            => [
                'user_id'         => $user->id,
                'plan_version_id' => $planVersionId,
                'plan_key'        => $plan->plan_key,
            ],
            'subscription_data'   => [
                'metadata' => [
                    'user_id'         => $user->id,
                    'plan_version_id' => $planVersionId,
                ],
            ],
        ]);
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
        $invoices = Invoice::all(['customer' => $customerId, 'limit' => $limit]);

        return collect($invoices->data)->map(fn (Invoice $inv) => [
            'id'           => $inv->id,
            'number'       => $inv->number,
            'date'         => $inv->created ? date('M j, Y', $inv->created) : null,
            'amount'       => number_format((($inv->amount_paid ?: $inv->amount_due) ?? 0) / 100, 2),
            'amount_cents' => (int) (($inv->amount_paid ?: $inv->amount_due) ?? 0),
            'currency'     => strtoupper((string) $inv->currency),
            'status'       => $inv->status, // paid | open | void | draft | uncollectible
            'hosted_url'   => $inv->hosted_invoice_url,
            'pdf_url'      => $inv->invoice_pdf,
        ])->all();
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
        $invoice = Invoice::retrieve($invoiceId);

        $params = [];
        if (! empty($invoice->payment_intent)) {
            $params['payment_intent'] = $invoice->payment_intent;
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
