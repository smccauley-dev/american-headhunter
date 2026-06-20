<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
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
    ) {}

    public function create(Request $request)
    {
        $user = User::findOrFail(session('auth.user_id'));

        $data = $request->validate([
            'plan_key' => 'required|string',
            'interval' => 'required|in:monthly,annual',
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

        // Lock the plan's current version; the webhook records it verbatim.
        $version = $this->subscriptions->currentVersionForPlan($plan->plan_key);

        $session = $this->stripe->createSubscriptionCheckoutSession(
            user:          $user,
            plan:          $plan,
            planVersionId: $version->id,
            interval:      $data['interval'],
            successUrl:    route('member.membership') . '?checkout=success',
            cancelUrl:     route('member.membership') . '?checkout=cancel',
        );

        return Inertia::location($session->url);
    }
}
