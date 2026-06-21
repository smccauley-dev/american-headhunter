<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Billing\PromoCodeService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Starts a hosted Stripe Checkout for a membership subscription. This endpoint
 * only creates the Checkout Session and redirects the browser to Stripe — the
 * local subscription is written later by ProcessStripeWebhook when Stripe fires
 * checkout.session.completed (the redirect itself is never trusted).
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly PromoCodeService $promoCodes,
    ) {}

    public function create(Request $request)
    {
        $user = User::findOrFail(session('auth.user_id'));

        $data = $request->validate([
            'plan_key'   => 'required|string',
            'interval'   => 'required|in:monthly,annual',
            'promo_code' => 'nullable|string|max:50',
        ]);

        // Phase 1 is the first-subscribe happy path; changing an existing
        // subscription (proration) lands in Phase 2 via the billing portal.
        if ($this->subscriptions->activeFor($user->id)) {
            return back()->withErrors(['plan_key' => 'You already have an active membership. Manage it from My Membership.']);
        }

        $plan = MembershipPlan::on('platform')
            ->where('plan_key', $data['plan_key'])
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return back()->withErrors(['plan_key' => 'That plan is not available.']);
        }

        if ($plan->account_type !== $user->account_type) {
            return back()->withErrors(['plan_key' => 'That plan is for a different account type.']);
        }

        if ($plan->monthly_price_cents <= 0 && $plan->annual_price_cents <= 0) {
            return back()->withErrors(['plan_key' => 'That plan is free — no checkout needed.']);
        }

        // Resolve a promo code: an explicit one is validated against this plan;
        // otherwise an auto-apply code shown on the plan's pricing card is used if
        // one is valid for this user. The discount is applied via the period's
        // synced Stripe Coupon; the code rides in metadata so the webhook records
        // the redemption after Stripe confirms payment.
        $promo  = null;
        $period = null;

        if (! empty($data['promo_code'])) {
            $result = $this->promoCodes->validateForPlan($data['promo_code'], $plan, $user);
            if (isset($result['error'])) {
                return back()->withErrors(['promo_code' => $result['error']]);
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
            interval:      $data['interval'],
            successUrl:    route('member.membership') . '?checkout=success',
            cancelUrl:     route('member.membership') . '?checkout=cancel',
            couponId:      $couponId,
            extraMetadata: $extraMetadata,
        );

        return Inertia::location($session->url);
    }
}
