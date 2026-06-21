<?php

namespace App\Services\Billing;

use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;

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
}
