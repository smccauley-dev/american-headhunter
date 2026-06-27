<?php

namespace App\Services\Billing;

use App\Models\Billing\PromotionClaim;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\PlanVersion;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Audit\AuditService;
use App\Services\Platform\EntitlementService;

/**
 * User-facing billing orchestration: subscribing, changing plans, cancelling,
 * and applying promotion claims. Plan-version mechanics are delegated to
 * SubscriptionService; this layer resolves the target plan, manages promotion
 * claims, and keeps the entitlement cache consistent.
 *
 * NOTE: Stripe-paid flows (creating a Stripe customer/subscription and charging)
 * are layered on in a later step via StripeService — these methods currently
 * create the local subscription/claim records and invalidate caches only.
 */
class BillingService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
        private readonly PromotionExpirationService $promotionExpiration,
    ) {}

    /**
     * Subscribe a user to a plan, locking the plan's current version.
     * Throws if the user already has an active subscription (use changePlan).
     *
     * Options are forwarded to SubscriptionService::start (interval, trial_days,
     * promotion_claim_id, status).
     */
    public function subscribe(User $user, string $planKey, array $opts = []): Subscription
    {
        $version = $this->subscriptions->currentVersionForPlan($planKey);

        $sub = $this->subscriptions->start($user->id, $version->id, $opts);

        // A member who was paused when a pause_account promo expired regains access
        // the moment they start paying again.
        $this->promotionExpiration->reactivate($user);

        return $sub;
    }

    /**
     * Move an existing subscription to a different plan (upgrade or downgrade),
     * locking the new plan's current version.
     */
    public function changePlan(User $user, string $newPlanKey, ?string $actorUserId = null): Subscription
    {
        $sub = $this->subscriptions->activeFor($user->id);
        if (! $sub) {
            throw new \RuntimeException("User {$user->id} has no active subscription to change.");
        }

        $version = $this->subscriptions->currentVersionForPlan($newPlanKey);

        return $this->subscriptions->swapVersion($sub, $version->id, $actorUserId);
    }

    /**
     * Cancel the user's active subscription. Returns null if there was none.
     */
    public function cancel(User $user, ?string $actorUserId = null): ?Subscription
    {
        $sub = $this->subscriptions->activeFor($user->id);
        if (! $sub) {
            return null;
        }

        return $this->subscriptions->cancel($sub, $actorUserId);
    }

    /**
     * Apply a promotional period to a user as an active promotion claim. If the
     * promo grants a tier, the claim records that plan's current version so
     * EntitlementService resolves features from it (highest precedence). If the
     * user has an active subscription, the claim is linked to it.
     *
     * Options: granted_plan_version_id, trigger_event, promo_code,
     * applied_by_user_id, referral_source_user_id.
     */
    public function applyPromotion(User $user, PromotionalPeriod $promo, array $opts = []): PromotionClaim
    {
        $grantedVersionId = $opts['granted_plan_version_id'] ?? null;
        if (! $grantedVersionId && $promo->grants_plan_id) {
            $grantedVersionId = $this->currentVersionIdForPlanId($promo->grants_plan_id);
        }

        $durationDays = $promo->duration_days;

        $claim = PromotionClaim::create([
            'user_id'                 => $user->id,
            'promotion_period_id'     => $promo->id,
            'status'                  => 'active',
            'granted_plan_id'         => $promo->grants_plan_id,
            'granted_plan_version_id' => $grantedVersionId,
            'duration_days'           => $durationDays,
            'discount_percentage'     => $promo->discount_percentage,
            'discount_amount_cents'   => $promo->discount_amount_cents,
            'activated_at'            => now(),
            'expires_at'              => $durationDays ? now()->addDays($durationDays) : null,
            'trigger_event'           => $opts['trigger_event'] ?? 'manual_admin',
            'promo_code_used'         => $opts['promo_code'] ?? null,
            'referral_source_user_id' => $opts['referral_source_user_id'] ?? null,
            'applied_by_user_id'      => $opts['applied_by_user_id'] ?? null,
        ]);

        if ($sub = $this->subscriptions->activeFor($user->id)) {
            $sub->update(['active_promotion_claim_id' => $claim->id]);
        }

        $this->audit->log(
            eventType:      'promotion.claimed',
            sourceDatabase: 'ah_billing',
            tableName:      'promotion_claims',
            recordId:       $claim->id,
            userId:         $user->id,
            actionSummary:  "Promotion {$promo->promo_key} applied ({$claim->trigger_event})",
        );
        $this->entitlements->invalidateForUser($user->id);

        return $claim;
    }

    private function currentVersionIdForPlanId(string $planId): ?string
    {
        $version = PlanVersion::on('platform')
            ->where('plan_id', $planId)
            ->whereNull('superseded_at')
            ->orderByDesc('version_number')
            ->first();

        return $version?->id;
    }
}
