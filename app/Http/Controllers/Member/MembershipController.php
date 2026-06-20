<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Carbon\Carbon;

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
}
