<?php

namespace App\Services\Billing;

use App\Models\Billing\PromotionClaim;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Audit\AuditService;
use App\Services\Identity\UserService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\Log;

/**
 * Terminal handling of an expired promotion claim (DB 4), branching on the
 * promotional period's on_expiration mode (DB 12):
 *
 *   - downgrade_free — the granted tier simply ends; the member reverts to their
 *     free tier. The claim is marked expired and unlinked from any subscription.
 *   - auto_charge    — convert the member to a paid subscription at the granted
 *     tier's live price, charging their default payment method. If they have no
 *     usable payment method (the common case for a free-promo signup), the charge
 *     fails and we fall back to a free downgrade.
 *   - pause_account  — block portal access (users.status = 'paused') until the
 *     member starts a paid subscription, which reactivates them.
 *
 * Every transition invalidates the member's entitlement cache and is audited.
 * All writes here run under ah_system (the queue worker / console).
 */
class PromotionExpirationService
{
    public function __construct(
        private readonly StripeService $stripe,
        private readonly SubscriptionService $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
        private readonly UserService $users,
    ) {}

    /**
     * Process one expired, still-active claim. Returns the outcome string:
     * 'downgraded_free' | 'converted' | 'auto_charge_failed' | 'paused' | 'skipped'.
     */
    public function expire(PromotionClaim $claim): string
    {
        $user = $this->users->findById($claim->user_id);
        if (! $user) {
            // The account is gone; close the claim so it stops being scanned.
            $this->markExpired($claim);

            return 'skipped';
        }

        $promo = PromotionalPeriod::on('platform')->find($claim->promotion_period_id);
        $mode  = $promo?->on_expiration ?? 'downgrade_free';

        return match ($mode) {
            'auto_charge'   => $this->autoCharge($claim, $user),
            'pause_account' => $this->pauseAccount($claim, $user),
            default         => $this->downgradeFree($claim, $user),
        };
    }

    /**
     * Lift a billing pause when the member starts paying. Returns true when a
     * paused account was reactivated. Call from the subscribe / checkout-completed
     * path so a paused member who resubscribes regains access.
     */
    public function reactivate(User $user): bool
    {
        if ($user->status !== 'paused') {
            return false;
        }

        $user->status = 'active';
        $user->save();

        $this->audit->log(
            eventType:      'account.reactivated',
            sourceDatabase: 'ah_identity',
            tableName:      'users',
            recordId:       $user->id,
            userId:         $user->id,
            actionSummary:  'Account reactivated from paused state after starting a paid subscription',
        );
        $this->entitlements->invalidateForUser($user->id);

        return true;
    }

    // ── Modes ────────────────────────────────────────────────────────────────────

    private function downgradeFree(PromotionClaim $claim, User $user): string
    {
        $this->markExpired($claim);
        $this->unlinkFromSubscription($claim);
        $this->entitlements->invalidateForUser($user->id);

        $this->audit->log(
            eventType:      'promotion.expired',
            sourceDatabase: 'ah_billing',
            tableName:      'promotion_claims',
            recordId:       $claim->id,
            userId:         $user->id,
            actionSummary:  'Promotion expired — member downgraded to free tier',
        );

        return 'downgraded_free';
    }

    private function autoCharge(PromotionClaim $claim, User $user): string
    {
        // A member who already pays needs no conversion — the promo was a discount
        // riding on their subscription; the discount simply ends.
        if ($this->subscriptions->activeFor($user->id)) {
            return $this->downgradeFree($claim, $user);
        }

        $plan    = $claim->granted_plan_id ? MembershipPlan::on('platform')->find($claim->granted_plan_id) : null;
        $priceId = $plan?->stripe_monthly_price_id;

        // Without a synced price or a locked version we cannot bill — fall back.
        if (! $priceId || ! $claim->granted_plan_version_id) {
            Log::warning('Promotion auto_charge: missing price or version, downgrading', ['claim_id' => $claim->id]);

            return $this->downgradeFree($claim, $user);
        }

        try {
            $customerId    = $this->stripe->getOrCreateCustomer($user);
            $stripeSub     = $this->stripe->createSubscription($customerId, $priceId, [
                'user_id'         => $user->id,
                'plan_version_id' => $claim->granted_plan_version_id,
            ]);
            $period        = $this->stripe->subscriptionPeriod($stripeSub->id);

            $this->subscriptions->start($user->id, $claim->granted_plan_version_id, [
                'stripe_subscription_id' => $stripeSub->id,
                'stripe_customer_id'     => $customerId,
                'interval'               => $period['interval'],
                'current_period_start'   => $period['current_period_start'],
                'current_period_end'     => $period['current_period_end'],
            ]);
        } catch (\Throwable $e) {
            // No usable payment method, or the first charge failed — downgrade so
            // the member is not left mid-conversion.
            Log::warning('Promotion auto_charge failed, downgrading', ['claim_id' => $claim->id, 'error' => $e->getMessage()]);
            $this->downgradeFree($claim, $user);

            return 'auto_charge_failed';
        }

        $claim->status       = 'converted';
        $claim->converted_at = now();
        $claim->save();
        $this->entitlements->invalidateForUser($user->id);

        $this->audit->log(
            eventType:      'promotion.converted',
            sourceDatabase: 'ah_billing',
            tableName:      'promotion_claims',
            recordId:       $claim->id,
            userId:         $user->id,
            actionSummary:  'Promotion expired — member auto-converted to a paid subscription',
        );

        return 'converted';
    }

    private function pauseAccount(PromotionClaim $claim, User $user): string
    {
        $this->markExpired($claim);
        $this->unlinkFromSubscription($claim);

        // Never override a moderation state; pause only an otherwise-active account.
        if ($user->status === 'active') {
            $user->status = 'paused';
            $user->save();
        }

        $this->entitlements->invalidateForUser($user->id);

        $this->audit->log(
            eventType:      'promotion.expired',
            sourceDatabase: 'ah_billing',
            tableName:      'promotion_claims',
            recordId:       $claim->id,
            userId:         $user->id,
            actionSummary:  'Promotion expired — account paused pending a paid subscription',
        );

        return 'paused';
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    private function markExpired(PromotionClaim $claim): void
    {
        $claim->status = 'expired';
        $claim->save();
    }

    /** Drop the claim from any subscription that still points at it. */
    private function unlinkFromSubscription(PromotionClaim $claim): void
    {
        Subscription::where('active_promotion_claim_id', $claim->id)
            ->update(['active_promotion_claim_id' => null]);
    }
}
