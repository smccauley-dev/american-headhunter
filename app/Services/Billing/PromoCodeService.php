<?php

namespace App\Services\Billing;

use App\Models\Billing\PromoCode;
use App\Models\Billing\PromotionClaim;
use App\Models\Identity\User;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanPromoCode;
use App\Models\Platform\PromotionalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Promo-code redemption rules for membership checkout. Codes live in DB 4
 * (billing); their benefit definition (PromotionalPeriod) and any plan
 * restriction (PlanPromoCode) live in DB 12 (platform) — assembled in PHP,
 * never joined across connections.
 *
 * A code linked to one or more plans (a plan_promo_codes row exists) is valid
 * ONLY on those plans. A code with no links is unrestricted.
 */
class PromoCodeService
{
    public function __construct(
        private readonly BillingService $billing,
    ) {}

    /**
     * Validate a code for a specific plan + user at checkout time.
     *
     * @return array{promo_code: PromoCode, period: PromotionalPeriod}|array{error: string}
     */
    public function validateForPlan(string $code, MembershipPlan $plan, User $user): array
    {
        $promo = PromoCode::on('billing')
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->first();

        if (! $promo || ! $promo->is_active) {
            return ['error' => 'That promo code is not valid.'];
        }

        if ($promo->starts_at && $promo->starts_at->isFuture()) {
            return ['error' => 'That promo code is not active yet.'];
        }

        if ($promo->expires_at && $promo->expires_at->isPast()) {
            return ['error' => 'That promo code has expired.'];
        }

        if (! is_null($promo->max_redemptions) && $promo->redemption_count >= $promo->max_redemptions) {
            return ['error' => 'That promo code has reached its redemption limit.'];
        }

        // Restriction: if the code is linked to any plan(s), this plan must be one.
        $restrictedPlanIds = PlanPromoCode::on('platform')
            ->where('promo_code_id', $promo->id)
            ->pluck('plan_id');

        if ($restrictedPlanIds->isNotEmpty() && ! $restrictedPlanIds->contains($plan->id)) {
            return ['error' => 'That promo code cannot be used on this plan.'];
        }

        $period = PromotionalPeriod::on('platform')->find($promo->promotional_period_id);
        if (! $period || ! $period->isActive()) {
            return ['error' => 'That promo code is not currently available.'];
        }

        $targets = $period->target_account_types ?? [];
        if (! empty($targets) && ! in_array($user->account_type, $targets, true)) {
            return ['error' => 'That promo code is not available for your account type.'];
        }

        $perUser = $promo->per_user_limit ?? 1;
        if ($perUser > 0) {
            $used = PromotionClaim::on('billing')
                ->where('user_id', $user->id)
                ->where('promo_code_used', $promo->code)
                ->count();

            if ($used >= $perUser) {
                return ['error' => 'You have already used this promo code.'];
            }
        }

        return ['promo_code' => $promo, 'period' => $period];
    }

    /**
     * The first pricing-card code that auto-applies for this plan + user, or null.
     * Auto-apply codes are exactly those toggled "show on pricing card".
     */
    public function autoApplyForPlan(MembershipPlan $plan, User $user): ?PromoCode
    {
        $links = PlanPromoCode::on('platform')
            ->where('plan_id', $plan->id)
            ->where('show_on_pricing_card', true)
            ->get();

        foreach ($links as $link) {
            $promo = PromoCode::on('billing')->find($link->promo_code_id);
            if (! $promo) {
                continue;
            }

            $result = $this->validateForPlan($promo->code, $plan, $user);
            if (! isset($result['error'])) {
                return $promo;
            }
        }

        return null;
    }

    /**
     * Record a successful redemption after a paid checkout: atomically increment
     * the code's redemption_count (guarded so concurrent redemptions can't exceed
     * max_redemptions), then create the promotion claim via BillingService (which
     * links the active subscription, audits, and invalidates entitlements).
     *
     * Called from the Stripe webhook; the caller wraps it so a promo failure
     * never fails the webhook.
     */
    public function recordRedemption(User $user, PromoCode $code): void
    {
        $period = PromotionalPeriod::on('platform')->find($code->promotional_period_id);
        if (! $period) {
            return;
        }

        // SEC-052: lock the code row so concurrent redemptions serialize on it,
        // and re-check BOTH the global cap and the per-user limit under the lock.
        // Validation happens at checkout-start but redemption is recorded later
        // at webhook time, so without this a user opening several checkouts could
        // pass the per-user check more than once. Different codes never contend.
        DB::connection('billing')->transaction(function () use ($user, $code, $period): void {
            $locked = PromoCode::on('billing')->whereKey($code->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if (! is_null($locked->max_redemptions) && $locked->redemption_count >= $locked->max_redemptions) {
                return;
            }

            $perUser = $locked->per_user_limit ?? 1;
            if ($perUser > 0) {
                $used = PromotionClaim::on('billing')
                    ->where('user_id', $user->id)
                    ->where('promo_code_used', $locked->code)
                    ->count();

                if ($used >= $perUser) {
                    return;
                }
            }

            $locked->increment('redemption_count');

            $this->billing->applyPromotion($user, $period, [
                'trigger_event' => 'promo_code',
                'promo_code'    => $code->code,
            ]);
        });
    }
}
