<?php

namespace App\Services\Billing;

use App\Models\Billing\PromoCode;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use Illuminate\Support\Facades\Log;

/**
 * Builds a hosted Stripe Checkout session for a membership subscription and
 * returns the URL to redirect the browser to. The local subscription is written
 * later by ProcessStripeWebhook on checkout.session.completed — the redirect
 * itself is never trusted.
 *
 * Shared by the member /pricing checkout (CheckoutController) and the signup
 * flow (AuthController::register), so a paid plan chosen at signup goes straight
 * to payment instead of being deferred.
 */
class MembershipCheckoutService
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly PromoCodeService $promoCodes,
    ) {}

    /**
     * @return array{url:string}|array{error:string,field:string}
     */
    public function start(
        User $user,
        string $planKey,
        string $interval,
        ?string $promoCode,
        string $successUrl,
        string $cancelUrl,
    ): array {
        if ($this->subscriptions->activeFor($user->id)) {
            return ['error' => 'You already have an active membership. Manage it from My Membership.', 'field' => 'plan_key'];
        }

        $plan = MembershipPlan::on('platform')
            ->where('plan_key', $planKey)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return ['error' => 'That plan is not available.', 'field' => 'plan_key'];
        }

        if ($plan->account_type !== $user->account_type) {
            return ['error' => 'That plan is for a different account type.', 'field' => 'plan_key'];
        }

        if ($plan->monthly_price_cents <= 0 && $plan->annual_price_cents <= 0) {
            return ['error' => 'That plan is free — no checkout needed.', 'field' => 'plan_key'];
        }

        // Resolve a promo code: an explicit one is validated against this plan;
        // otherwise an auto-apply code shown on the plan's pricing card is used if
        // one is valid for this user. The discount is applied via the period's
        // synced Stripe Coupon; the code rides in metadata so the webhook records
        // the redemption after Stripe confirms payment.
        $promo  = null;
        $period = null;

        if (! empty($promoCode)) {
            $result = $this->promoCodes->validateForPlan($promoCode, $plan, $user);
            if (isset($result['error'])) {
                return ['error' => $result['error'], 'field' => 'promo_code'];
            }
            $promo  = $result['promo_code'];
            $period = $result['period'];
        } elseif ($auto = $this->promoCodes->autoApplyForPlan($plan, $user)) {
            $promo  = $auto;
            $period = PromotionalPeriod::on('platform')->find($auto->promotional_period_id);
        }

        // A promo that grants a price discount must have a synced Stripe Coupon, or
        // hosted Checkout would silently charge full price. Refuse rather than
        // overcharge — coupons are wired automatically when a promotion is activated,
        // so this only trips on one that was never synced (e.g. Stripe was down at
        // activation; the fix is to re-save the promotion or run stripe:sync-promos).
        if ($period && $this->grantsDiscount($period) && empty($period->stripe_coupon_id)) {
            return [
                'error' => 'This promo code is not ready yet. Please try again shortly or contact support.',
                'field' => ! empty($promoCode) ? 'promo_code' : 'plan_key',
            ];
        }

        $couponId      = $period?->stripe_coupon_id;
        $extraMetadata = $promo
            ? ['promo_code_id' => (string) $promo->id, 'promotional_period_id' => (string) $period->id]
            : [];

        // Lock the plan's current version; the webhook records it verbatim.
        $version = $this->subscriptions->currentVersionForPlan($plan->plan_key);

        $session = $this->stripe->createSubscriptionCheckoutSession(
            user:          $user,
            plan:          $plan,
            planVersionId: $version->id,
            interval:      $interval,
            successUrl:    $successUrl,
            cancelUrl:     $cancelUrl,
            couponId:      $couponId,
            extraMetadata: $extraMetadata,
        );

        return ['url' => $session->url];
    }

    /** A period that grants a price discount needs a Stripe Coupon to apply it. */
    private function grantsDiscount(PromotionalPeriod $period): bool
    {
        return ($period->discount_percentage && $period->discount_percentage > 0)
            || ($period->discount_amount_cents && $period->discount_amount_cents > 0);
    }

    /**
     * Reconcile a completed subscription Checkout into a local subscription row.
     * Shared by ProcessStripeWebhook (checkout.session.completed) and the instant
     * reactivation return (ReactivationController::return) so a paused member lands
     * back in the portal active without waiting for the webhook. Idempotent — a
     * replay or a race with the webhook is a no-op.
     *
     * Returns true when a subscription exists for the user after this call (created
     * here or already present), so the caller knows it can lift a billing pause.
     */
    public function recordSubscriptionFromCheckout(array $session): bool
    {
        if (($session['mode'] ?? null) !== 'subscription') {
            return false;
        }

        $stripeSubId   = $session['subscription'] ?? null;
        $stripeCustId  = $session['customer'] ?? null;
        $userId        = $session['metadata']['user_id'] ?? null;
        $planVersionId = $session['metadata']['plan_version_id'] ?? null;

        if (! $stripeSubId || ! $userId || ! $planVersionId) {
            Log::warning('MembershipCheckout: subscription checkout missing fields', ['stripe_subscription_id' => $stripeSubId]);
            return false;
        }

        // Idempotent: the subscription may already exist from a replay or a racing
        // customer.subscription.* event (or the webhook beating this return).
        if (Subscription::where('stripe_subscription_id', $stripeSubId)->exists()) {
            return true;
        }

        // Read the real interval + period window from Stripe so the renew date and
        // price cadence on the membership card are authoritative rather than a
        // local guess. A read failure must not block creating the subscription —
        // fall back to start()'s estimate (monthly).
        $periodOpts = [];
        try {
            $period = $this->stripe->subscriptionPeriod($stripeSubId);
            $periodOpts = [
                'interval'             => $period['interval'],
                'current_period_start' => $period['current_period_start'],
                'current_period_end'   => $period['current_period_end'],
            ];
        } catch (\Throwable $e) {
            Log::warning('MembershipCheckout: could not read subscription period', ['error' => $e->getMessage(), 'stripe_subscription_id' => $stripeSubId]);
        }

        $created = false;
        try {
            $this->subscriptions->start($userId, $planVersionId, array_merge([
                'stripe_subscription_id' => $stripeSubId,
                'stripe_customer_id'     => $stripeCustId,
                'status'                 => 'active',
            ], $periodOpts));
            $created = true;
        } catch (\RuntimeException $e) {
            // start() throws if the user already holds an active subscription —
            // treat as already-reconciled rather than failing the caller. An
            // unexpected error still bubbles so the webhook retries.
            Log::info('MembershipCheckout: subscription reconcile skipped', ['error' => $e->getMessage(), 'user_id' => $userId]);
            return true;
        }

        // A promo code rode along on the checkout metadata — record the redemption
        // (increment the code's count + author the claim) now that the paid
        // subscription exists. A promo failure must never fail the caller.
        $promoCodeId = $session['metadata']['promo_code_id'] ?? null;
        if ($created && $promoCodeId) {
            try {
                $user = User::find($userId);
                $code = PromoCode::on('billing')->find($promoCodeId);
                if ($user && $code) {
                    $this->promoCodes->recordRedemption($user, $code);
                }
            } catch (\Throwable $e) {
                Log::warning('MembershipCheckout: promo redemption failed', ['error' => $e->getMessage(), 'promo_code_id' => $promoCodeId]);
            }
        }

        return true;
    }
}
