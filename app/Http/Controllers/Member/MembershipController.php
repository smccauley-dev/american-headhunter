<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Services\Audit\AuditService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Self-service management of an existing membership subscription. Cancellation is
 * scheduled at period end (the member keeps access through what they've paid for)
 * and is reversible via resume() until the period actually ends. Stripe is the
 * source of truth; these actions tell Stripe what to do and optimistically mirror
 * the result locally — ProcessStripeWebhook reconciles the authoritative state.
 */
class MembershipController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    public function cancel()
    {
        $user = User::findOrFail(session('auth.user_id'));
        $sub  = $this->subscriptions->activeFor($user->id);

        if (! $sub || ! $sub->stripe_subscription_id) {
            return back()->withErrors(['membership' => 'You have no active paid membership to cancel.']);
        }

        if ($sub->cancelled_at) {
            return back(); // already scheduled to cancel — nothing to do
        }

        $stripeSub = $this->stripe->cancelSubscriptionAtPeriodEnd($sub->stripe_subscription_id);

        $cancelAt = $stripeSub->cancel_at ?? $stripeSub->current_period_end ?? null;
        $sub->cancelled_at = $cancelAt ? Carbon::createFromTimestamp($cancelAt) : now();
        $sub->save();

        $this->entitlements->invalidateForUser($user->id);
        $this->audit->log(
            eventType:      'subscription.cancel_scheduled',
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $user->id,
            actionSummary:  'Member scheduled cancellation at period end',
            newValues:      ['cancelled_at' => $sub->cancelled_at?->toDateString()],
        );

        return back();
    }

    public function resume()
    {
        $user = User::findOrFail(session('auth.user_id'));
        $sub  = $this->subscriptions->activeFor($user->id);

        if (! $sub || ! $sub->stripe_subscription_id || ! $sub->cancelled_at) {
            return back()->withErrors(['membership' => 'There is no scheduled cancellation to resume.']);
        }

        $this->stripe->resumeSubscription($sub->stripe_subscription_id);

        $sub->cancelled_at = null;
        $sub->save();

        $this->entitlements->invalidateForUser($user->id);
        $this->audit->log(
            eventType:      'subscription.resumed',
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $user->id,
            actionSummary:  'Member resumed a subscription scheduled to cancel',
        );

        return back();
    }

    /**
     * Switch the member's active subscription to a different paid plan,
     * immediately and with proration. The billing interval is preserved by
     * StripeService (it reads the current interval from Stripe). swapVersion
     * updates the locked plan_version_id locally, audits, and invalidates the
     * entitlement cache; the webhook later reconciles the authoritative state.
     */
    public function changePlan(Request $request)
    {
        $data = $request->validate(['plan_key' => 'required|string']);

        $user = User::findOrFail(session('auth.user_id'));
        $sub  = $this->subscriptions->activeFor($user->id);

        if (! $sub || ! $sub->stripe_subscription_id) {
            return back()->withErrors(['plan_key' => 'You have no active paid membership to change.']);
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
            return back()->withErrors(['plan_key' => 'That plan is free — cancel your membership instead.']);
        }

        $version = $this->subscriptions->currentVersionForPlan($plan->plan_key);

        if ($version->id === $sub->plan_version_id) {
            return back()->withErrors(['plan_key' => 'You are already on that plan.']);
        }

        $this->stripe->changeSubscriptionPlan($sub->stripe_subscription_id, $plan, $version->id);
        $this->subscriptions->swapVersion($sub, $version->id, $user->id);

        return back();
    }

    /**
     * Start a hosted Checkout (setup mode) so the member can replace a failed
     * card. The card-update is finished by ProcessStripeWebhook when Stripe fires
     * checkout.session.completed — the redirect itself is never trusted.
     */
    public function updatePayment()
    {
        $user = User::findOrFail(session('auth.user_id'));
        $sub  = $this->subscriptions->activeFor($user->id);

        if (! $sub || ! $sub->stripe_subscription_id) {
            return back()->withErrors(['membership' => 'You have no active membership that needs a payment method.']);
        }

        $session = $this->stripe->createPaymentUpdateCheckoutSession(
            user:       $user,
            successUrl: route('member.membership') . '?billing=updated',
            cancelUrl:  route('member.membership') . '?billing=cancel',
        );

        return Inertia::location($session->url);
    }
}
