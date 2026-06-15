<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\Audit\AuditService;
use App\Services\Platform\EntitlementService;

/**
 * Record-level subscription mechanics: locking a plan version at creation,
 * trial handling, cancellation, and version swaps (grandfathering).
 *
 * A subscription locks onto a specific plan_version_id and that lock never
 * changes on its own — when a plan's pricing/entitlements change, a new
 * plan_version is created and existing subscribers keep their version. The only
 * way the lock moves is an explicit changePlan/swapVersion.
 *
 * Subscription records live in DB 4 (billing); plan versions in DB 12
 * (platform). Resolution is assembled in PHP — no cross-connection relationship.
 *
 * Every mutation invalidates the user's entitlement cache so EntitlementService
 * never serves stale features, regardless of caller.
 */
class SubscriptionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The user's current subscription, if any. At most one can exist in these
     * statuses (enforced by the partial unique index uq_subscriptions_user_active).
     */
    public function activeFor(string $userId): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first();
    }

    /**
     * The plan version a new subscription on $planKey should lock onto — the
     * latest non-superseded version of that plan.
     */
    public function currentVersionForPlan(string $planKey): PlanVersion
    {
        $plan = MembershipPlan::on('platform')->where('plan_key', $planKey)->first();
        if (! $plan) {
            throw new \RuntimeException("Unknown plan key: {$planKey}");
        }

        $version = PlanVersion::on('platform')
            ->where('plan_id', $plan->id)
            ->whereNull('superseded_at')
            ->orderByDesc('version_number')
            ->first();

        if (! $version) {
            throw new \RuntimeException("Plan {$planKey} has no current version.");
        }

        return $version;
    }

    /**
     * Create a subscription locked to a plan version.
     *
     * Options:
     *   interval            'monthly' (default) | 'annual'  — drives period_end
     *   trial_days          int — if set, status is 'trialing' and the period
     *                       runs to the trial end
     *   status              explicit status override (when not a trial)
     *   promotion_claim_id  link to an active promotion claim
     *   stripe_subscription_id / stripe_customer_id  (deferred — null for now)
     */
    public function start(string $userId, string $planVersionId, array $opts = []): Subscription
    {
        if ($this->activeFor($userId)) {
            throw new \RuntimeException("User {$userId} already has an active subscription.");
        }

        $start     = now()->startOfDay();
        $trialDays = $opts['trial_days'] ?? null;

        if ($trialDays) {
            $status    = 'trialing';
            $trialEnds = now()->addDays($trialDays);
            $end       = $start->copy()->addDays($trialDays);
        } else {
            $status    = $opts['status'] ?? 'active';
            $trialEnds = null;
            $end       = ($opts['interval'] ?? 'monthly') === 'annual'
                ? $start->copy()->addYear()
                : $start->copy()->addMonth();
        }

        $sub = Subscription::create([
            'user_id'                   => $userId,
            'plan_version_id'           => $planVersionId,
            'active_promotion_claim_id' => $opts['promotion_claim_id'] ?? null,
            'stripe_subscription_id'    => $opts['stripe_subscription_id'] ?? null,
            'stripe_customer_id'        => $opts['stripe_customer_id'] ?? null,
            'status'                    => $status,
            'current_period_start'      => $start->toDateString(),
            'current_period_end'        => $end->toDateString(),
            'trial_ends_at'             => $trialEnds,
        ]);

        $this->recordAudit('subscription.created', $sub, $userId,
            "Subscription created on plan version {$planVersionId} ({$status})");
        $this->entitlements->invalidateForUser($userId);

        return $sub;
    }

    public function cancel(Subscription $sub, ?string $actorUserId = null): Subscription
    {
        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->recordAudit('subscription.cancelled', $sub, $actorUserId ?? $sub->user_id,
            'Subscription cancelled');
        $this->entitlements->invalidateForUser($sub->user_id);

        return $sub;
    }

    /**
     * Move a subscription onto a different plan version (upgrade/downgrade). The
     * lock only ever changes through this explicit call.
     */
    public function swapVersion(Subscription $sub, string $newPlanVersionId, ?string $actorUserId = null): Subscription
    {
        $oldVersionId = $sub->plan_version_id;
        $sub->update(['plan_version_id' => $newPlanVersionId]);

        $this->recordAudit('subscription.plan_changed', $sub, $actorUserId ?? $sub->user_id,
            "Plan version changed from {$oldVersionId} to {$newPlanVersionId}",
            ['plan_version_id' => $oldVersionId],
            ['plan_version_id' => $newPlanVersionId]);
        $this->entitlements->invalidateForUser($sub->user_id);

        return $sub;
    }

    private function recordAudit(
        string $event,
        Subscription $sub,
        ?string $userId,
        string $summary,
        ?array $old = null,
        ?array $new = null,
    ): void {
        $this->audit->log(
            eventType:      $event,
            sourceDatabase: 'ah_billing',
            tableName:      'subscriptions',
            recordId:       $sub->id,
            userId:         $userId,
            actionSummary:  $summary,
            oldValues:      $old,
            newValues:      $new,
        );
    }
}
