<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\AccountLink;
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
use Stripe\Transfer;
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

        // The admin chooses the discount's lifetime explicitly: once (first
        // invoice only), repeating for N months, or forever (every renewal).
        $duration                = in_array($promo->discount_duration, ['once', 'repeating', 'forever'], true)
            ? $promo->discount_duration
            : 'once';
        $params['duration'] = $duration;
        if ($duration === 'repeating') {
            $params['duration_in_months'] = max(1, (int) ($promo->discount_duration_months ?? 1));
        }

        return Coupon::create($params)->id;
    }

    /**
     * Sync a period's monetary discount to a Stripe Coupon and persist the id back
     * onto the period when it changes. Returns the coupon id (or null for a promo
     * with no monetary discount). Shared by the stripe:sync-promos backfill command
     * and the admin create/edit pages, so activating a promotion in Filament wires
     * its coupon immediately rather than waiting for the command.
     */
    public function syncPromotionCoupon(PromotionalPeriod $promo): ?string
    {
        // Stripe coupons are immutable. If the period's discount terms have since
        // diverged from the stored coupon (e.g. an admin changed the percentage or
        // the duration), drop the reference so a fresh coupon is minted. The old
        // coupon stays in Stripe and existing subscribers keep it — only new
        // checkouts pick up the new terms.
        if ($promo->stripe_coupon_id && ! $this->couponMatches($promo)) {
            $promo->stripe_coupon_id = null;
            $promo->save();
        }

        $couponId = $this->upsertCoupon($promo);

        if ($couponId && $couponId !== $promo->stripe_coupon_id) {
            $promo->stripe_coupon_id = $couponId;
            $promo->save();
        }

        return $couponId;
    }

    /**
     * Whether the period's stored Stripe Coupon still reflects its current
     * discount terms (amount/percentage and duration). A retrieval failure or any
     * mismatch returns false so the caller re-mints the coupon.
     */
    private function couponMatches(PromotionalPeriod $promo): bool
    {
        try {
            $coupon = Coupon::retrieve($promo->stripe_coupon_id);
        } catch (\Throwable $e) {
            return false;
        }

        $wantDuration = in_array($promo->discount_duration, ['once', 'repeating', 'forever'], true)
            ? $promo->discount_duration
            : 'once';
        if (($coupon->duration ?? null) !== $wantDuration) {
            return false;
        }
        if ($wantDuration === 'repeating'
            && (int) ($coupon->duration_in_months ?? 0) !== max(1, (int) ($promo->discount_duration_months ?? 1))) {
            return false;
        }

        if ($promo->discount_percentage && $promo->discount_percentage > 0) {
            return abs((float) ($coupon->percent_off ?? 0) - (float) $promo->discount_percentage) < 0.001;
        }
        if ($promo->discount_amount_cents && $promo->discount_amount_cents > 0) {
            return (int) ($coupon->amount_off ?? 0) === (int) $promo->discount_amount_cents;
        }

        return true;
    }

    /**
     * Read a subscription's currently-active discount (if any) from Stripe — the
     * coupon terms that will actually reduce upcoming charges. Returns null when no
     * discount applies: no coupon, an invalid/expired one, or a `once` coupon that
     * was already consumed on the first invoice (its discounts array is empty).
     *
     * @return array{percent_off:?float, amount_off:?int, duration:string, duration_in_months:?int, ends_at:?string}|null
     */
    public function subscriptionDiscount(string $stripeSubscriptionId): ?array
    {
        $sub = StripeSubscription::retrieve([
            'id'     => $stripeSubscriptionId,
            'expand' => ['discounts'],
        ]);

        // Newer API exposes `discounts` (array of Discount objects when expanded);
        // fall back to the legacy singular `discount`.
        $discount = $sub->discounts[0] ?? $sub->discount ?? null;
        if (! $discount || is_string($discount)) {
            return null;
        }

        $coupon = $discount->coupon ?? null;
        if (! $coupon || ! ($coupon->valid ?? true)) {
            return null;
        }

        return [
            'percent_off'        => $coupon->percent_off !== null ? (float) $coupon->percent_off : null,
            'amount_off'         => $coupon->amount_off  !== null ? (int) $coupon->amount_off : null,
            'duration'           => (string) ($coupon->duration ?? 'once'),
            'duration_in_months' => $coupon->duration_in_months !== null ? (int) $coupon->duration_in_months : null,
            'ends_at'            => ! empty($discount->end)
                ? \Carbon\Carbon::createFromTimestamp($discount->end)->format('M j, Y')
                : null,
        ];
    }

    /**
     * Create a Stripe subscription directly via the API (no hosted Checkout),
     * charging the customer's existing default payment method. Used when a free
     * promotional period whose on_expiration is 'auto_charge' lapses and the
     * member is converted to a paid subscription at the granted tier's price.
     *
     * payment_behavior 'error_if_incomplete' makes Stripe throw synchronously when
     * the customer has no usable payment method or the first charge fails, so the
     * caller can fall back to a free downgrade instead of leaving a dangling
     * incomplete subscription. The locked plan_version_id rides in metadata so the
     * reconciling webhook records it verbatim.
     *
     * @param array<string,string> $metadata
     */
    public function createSubscription(string $customerId, string $priceId, array $metadata = []): StripeSubscription
    {
        return StripeSubscription::create([
            'customer'         => $customerId,
            'items'            => [['price' => $priceId]],
            'payment_behavior' => 'error_if_incomplete',
            'metadata'         => $metadata,
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
    public function createDepositCheckoutSession(User $payer, int $amountCents, array $metadata, string $successUrl, string $cancelUrl, string $productName = 'Refundable security deposit'): Session
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
                    'product_data' => ['name' => $productName],
                ],
            ]],
            'payment_intent_data' => ['metadata' => $metadata],
            'metadata'            => $metadata,
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ]);
    }

    /**
     * Create a one-time hosted Checkout Session for a destination charge: the
     * customer pays on the platform account, but transfer_data[destination] routes
     * the net to the landowner's connected account, application_fee_amount is the
     * platform's cut, and on_behalf_of attributes settlement + tax (the landowner's
     * 1099-K) to them. The lease + party references ride in metadata (mirrored onto
     * the PaymentIntent) so the webhook can author the row — no local write happens
     * on the runtime path.
     *
     * @param array<string,string> $metadata
     */
    public function createConnectCheckoutSession(
        User $payer,
        int $grossCents,
        int $applicationFeeCents,
        string $destinationAccountId,
        array $metadata,
        string $successUrl,
        string $cancelUrl,
        string $productName,
    ): Session {
        return Session::create([
            'mode'                => 'payment',
            'customer'            => $this->getOrCreateCustomer($payer),
            'client_reference_id' => $payer->id,
            'line_items'          => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $grossCents,
                    'product_data' => ['name' => $productName],
                ],
            ]],
            'payment_intent_data' => [
                'application_fee_amount' => $applicationFeeCents,
                'transfer_data'          => ['destination' => $destinationAccountId],
                'on_behalf_of'           => $destinationAccountId,
                'metadata'               => $metadata,
            ],
            'metadata'            => $metadata,
            'success_url'         => $successUrl,
            'cancel_url'          => $cancelUrl,
        ]);
    }

    /**
     * Retrieve a Checkout Session by id. Used by the deposit success-return path to
     * author the held row immediately rather than waiting on the webhook; the
     * returned shape (metadata, payment_intent, currency, amount_total) matches the
     * checkout.session.completed payload SecurityDepositService::recordHeldFromCheckout
     * expects.
     */
    public function retrieveCheckoutSession(string $sessionId): Session
    {
        return Session::retrieve($sessionId);
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
     * Refund a destination-charge PaymentIntent, reversing the transfer to the
     * landowner and returning the platform's application fee. Without reverse_transfer
     * the landowner keeps their net and the platform eats the refund; without
     * refund_application_fee the platform keeps its cut on a refunded sale. A null
     * amount refunds in full; a cents amount refunds partially (Stripe reverses the
     * transfer and application fee proportionally). Returns the Stripe Refund.
     */
    public function refundDestinationCharge(string $paymentIntentId, ?int $amountCents = null): Refund
    {
        $params = [
            'payment_intent'         => $paymentIntentId,
            'reverse_transfer'       => true,
            'refund_application_fee' => true,
        ];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }

        return $this->withoutStripeNotice(fn () => Refund::create($params));
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
            // Stripe requires a currency for setup-mode Checkout sessions.
            'currency'          => 'usd',
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

    // ── Connect — landowner payouts ──────────────────────────────────────────────

    /**
     * Create an Express connected account for a landowner so the platform can
     * transfer their lease revenue to them. The user_id rides in metadata so the
     * account.updated webhook can correlate the account back to a local record.
     * Returns the Stripe account id (the caller persists it under ah_system).
     *
     * Both card_payments and transfers are requested: our charges use on_behalf_of,
     * which makes the connected account the settlement merchant, so Stripe requires
     * it to hold card_payments (transfers alone is rejected at charge time).
     */
    public function createConnectAccount(User $landowner): string
    {
        $account = $this->withoutStripeNotice(fn () => Account::create([
            'type'         => 'express',
            'email'        => $landowner->email,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers'     => ['requested' => true],
            ],
            'metadata'     => ['user_id' => $landowner->id],
        ]));

        return $account->id;
    }

    /**
     * Create a single-use hosted onboarding link for a connected account. The
     * landowner completes identity / bank details on Stripe; refresh_url is hit if
     * the link expires before they finish, return_url when they come back. Returns
     * the URL to redirect to.
     */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->withoutStripeNotice(fn () => AccountLink::create([
            'account'     => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url'  => $returnUrl,
            'type'        => 'account_onboarding',
        ])->url);
    }

    /**
     * Retrieve a connected account — used by the webhook to read the authoritative
     * charges_enabled / payouts_enabled / details_submitted flags.
     */
    public function retrieveAccount(string $accountId): Account
    {
        return $this->withoutStripeNotice(fn () => Account::retrieve($accountId));
    }

    /**
     * Transfer funds from the platform balance to a landowner's connected account
     * (the net lease revenue after the platform fee). currency is fixed to USD; the
     * destination is the landowner's Stripe Connect account id. Returns the Transfer
     * so the caller can record its id on the payout row.
     *
     * @param array<string,string> $metadata
     */
    public function createTransfer(int $amountCents, string $destinationAccountId, array $metadata = []): Transfer
    {
        return $this->withoutStripeNotice(fn () => Transfer::create([
            'amount'      => $amountCents,
            'currency'    => 'usd',
            'destination' => $destinationAccountId,
            'metadata'    => $metadata,
        ]));
    }

    /**
     * Reverse all or part of a transfer, pulling funds back from a landowner's
     * connected account to the platform balance. This is the counterpart to
     * createTransfer that a refund must perform: under separate charges & transfers
     * the landowner was already paid their net, so refunding the customer without
     * reversing the transfer would leave the landowner overpaid and the platform
     * out of pocket. A null amount reverses the full remaining transfer; a cents
     * amount reverses partially. Returns the Stripe TransferReversal.
     *
     * @param array<string,string> $metadata
     */
    public function reverseTransfer(string $transferId, ?int $amountCents = null, array $metadata = []): \Stripe\TransferReversal
    {
        $params = [];
        if ($amountCents !== null) {
            $params['amount'] = $amountCents;
        }
        if ($metadata !== []) {
            $params['metadata'] = $metadata;
        }

        return $this->withoutStripeNotice(fn () => Transfer::createReversal($transferId, $params));
    }

    /**
     * Pull funds from a connected account's Stripe balance back to the platform —
     * the counterpart to createTransfer, used to recover a non-recoverable cost from
     * a landowner. On a clean security-deposit release the platform refunds the
     * hunter from its own balance and Stripe keeps its (non-refundable) processing
     * fee; that fee is the landowner's cost, so we debit it from their balance.
     *
     * Implemented as a Transfer that originates in the connected account's context
     * (the Stripe-Account header) and is destined to the platform account id. Throws
     * Stripe\Exception\* when the connected account has insufficient balance — the
     * caller treats any failure as "defer / record as owed", never as fatal.
     *
     * @param array<string,string> $metadata
     */
    public function debitConnectedAccount(string $connectedAccountId, int $amountCents, array $metadata = []): Transfer
    {
        return $this->withoutStripeNotice(fn () => Transfer::create(
            [
                'amount'      => $amountCents,
                'currency'    => 'usd',
                'destination' => $this->platformAccountId(),
                'metadata'    => $metadata,
            ],
            ['stripe_account' => $connectedAccountId],
        ));
    }

    /**
     * The platform's own Stripe account id (the account the secret key belongs to),
     * resolved once per instance via GET /v1/account. Needed as the destination when
     * pulling funds back from a connected account in debitConnectedAccount.
     */
    private function platformAccountId(): string
    {
        return $this->platformAccountId ??= (string) $this->withoutStripeNotice(
            fn () => Account::retrieve()->id,
        );
    }

    private ?string $platformAccountId = null;

    /**
     * The actual Stripe processing fee (in cents) charged on a captured payment,
     * read from the charge's balance transaction — the authoritative figure, not an
     * estimate. Used when allocating who bears the lost Stripe fee on a refund
     * (Stripe keeps its fee when a charge is refunded). Returns 0 when the charge
     * has no settled balance transaction yet.
     */
    public function chargeStripeFee(string $chargeId): int
    {
        $charge = Charge::retrieve(['id' => $chargeId, 'expand' => ['balance_transaction']]);
        $txn    = $charge->balance_transaction;

        return is_object($txn) ? (int) ($txn->fee ?? 0) : 0;
    }

    /**
     * Resolve the charge id backing a PaymentIntent — needed to read the Stripe
     * fee (chargeStripeFee) and to correlate a refund back to its original charge.
     * Returns null when the intent has no captured charge.
     */
    public function chargeIdForPaymentIntent(string $paymentIntentId): ?string
    {
        $intent = \Stripe\PaymentIntent::retrieve([
            'id'     => $paymentIntentId,
            'expand' => ['latest_charge'],
        ]);

        $charge = $intent->latest_charge ?? null;

        if (is_object($charge)) {
            return (string) $charge->id;
        }

        return is_string($charge) && $charge !== '' ? $charge : null;
    }

    /**
     * The captured charge id and the destination transfer id behind a PaymentIntent.
     * For a destination charge Stripe auto-creates the transfer to the connected
     * account and records its id on the charge's `transfer` field. Used by the
     * lease-payment webhook to capture both ids on the collected row. Returns nulls
     * when the intent has no settled charge / transfer yet.
     *
     * @return array{charge_id:?string, transfer_id:?string}
     */
    public function chargeAndTransferForPaymentIntent(string $paymentIntentId): array
    {
        $intent = \Stripe\PaymentIntent::retrieve([
            'id'     => $paymentIntentId,
            'expand' => ['latest_charge'],
        ]);

        $charge = $intent->latest_charge ?? null;
        if (! is_object($charge)) {
            return [
                'charge_id'   => is_string($charge) && $charge !== '' ? $charge : null,
                'transfer_id' => null,
            ];
        }

        $transfer   = $charge->transfer ?? null;
        $transferId = is_object($transfer)
            ? (string) $transfer->id
            : (is_string($transfer) && $transfer !== '' ? $transfer : null);

        return ['charge_id' => (string) $charge->id, 'transfer_id' => $transferId];
    }

    /**
     * Run a Stripe SDK call while neutralizing the informational `stripe-notice`
     * response header. Recent Stripe API versions return that header on the v1
     * Account endpoints (recommending Accounts v2); stripe-php emits it via
     * trigger_error(E_USER_WARNING), which Laravel's error handler would otherwise
     * promote to a fatal ErrorException — aborting a request whose API call
     * actually succeeded. Genuine API failures throw Stripe\Exception\* and are
     * unaffected by this.
     *
     * @template T
     *
     * @param  \Closure():T  $call
     * @return T
     */
    private function withoutStripeNotice(\Closure $call): mixed
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            Log::debug('Stripe notice suppressed', ['notice' => $errstr]);

            return true; // handled — do not promote to an ErrorException
        }, \E_USER_WARNING);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
