<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;
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
}
