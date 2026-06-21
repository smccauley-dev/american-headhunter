<?php

namespace App\Services\Billing;

use App\Models\Billing\PromotionClaim;
use App\Models\Identity\User;
use App\Models\Platform\PromotionalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Trigger-based promotion grants (signup, first listing). Unlike promo codes,
 * these apply automatically — no code, no checkout. They only do anything for
 * grant-type promos (a promotional_period with grants_plan_id set): the claim's
 * granted_plan_version_id is what EntitlementService elevates, and that takes
 * precedence over any subscription. A percentage/dollar discount has no
 * grants_plan_id, so it is intentionally ignored here — those discount at
 * checkout via the plan-linked coupon path, not at signup.
 *
 * Cross-DB by hand: periods live in DB 12 (platform), claims in DB 4 (billing).
 */
class PromotionAutoApplyService
{
    public function __construct(
        private readonly BillingService $billing,
    ) {}

    public function applyForSignup(User $user): void
    {
        $this->applyTrigger($user, 'auto_apply_on_signup', 'signup');
    }

    public function applyForFirstListing(User $user): void
    {
        $this->applyTrigger($user, 'auto_apply_on_first_listing', 'first_listing');
    }

    /**
     * Presentational preview of the best signup grant available to a given
     * account type, for advertising on the signup page. State-targeted promos
     * are deliberately excluded: at page render we don't yet know the user's
     * state, so we never advertise a grant the user might not actually receive.
     * Eligibility (window, claim limit, once-per-user) is re-checked
     * authoritatively at apply time in applyForSignup().
     *
     * @return array{headline: string, detail: string}|null
     */
    public function previewForSignup(string $accountType): ?array
    {
        $period = PromotionalPeriod::on('platform')
            ->where('auto_apply_on_signup', true)
            ->where('status', 'active')
            ->whereNotNull('grants_plan_id')
            ->get()
            ->first(function (PromotionalPeriod $period) use ($accountType): bool {
                if (! $period->isActive()) {
                    return false;
                }
                if (! empty($period->target_states ?? [])) {
                    return false; // state unknown at signup-page render
                }
                $types = $period->target_account_types ?? [];

                return empty($types) || in_array($accountType, $types, true);
            });

        if ($period === null) {
            return null;
        }

        $planName = $period->grantsPlan?->display_name ?? 'a premium membership';
        $window   = $period->duration_days ? " for {$period->duration_days} days" : '';

        return [
            'headline' => $period->pricing_badge_text ?: 'New member offer',
            'detail'   => $period->landing_banner_text
                ?: "You'll get {$planName}{$window} at no cost — applied automatically when you create your account.",
        ];
    }

    /**
     * Grant every eligible auto-apply promotion for this trigger. Each grant is a
     * separate deliberate admin config; EntitlementService resolves the
     * highest-precedence active claim, so additive claims are safe.
     */
    private function applyTrigger(User $user, string $flagColumn, string $triggerEvent): void
    {
        $periods = PromotionalPeriod::on('platform')
            ->where($flagColumn, true)
            ->where('status', 'active')
            ->whereNotNull('grants_plan_id')
            ->get();

        foreach ($periods as $period) {
            if (! $this->isEligible($user, $period)) {
                continue;
            }

            // Authoritative claim-limit gate: only grant if we win the atomic,
            // conditional increment (null claim_limit = unlimited).
            $incremented = PromotionalPeriod::on('platform')
                ->whereKey($period->id)
                ->where(function ($q): void {
                    $q->whereNull('claim_limit')
                        ->orWhereColumn('claim_count', '<', 'claim_limit');
                })
                ->update(['claim_count' => DB::raw('claim_count + 1')]);

            if (! $incremented) {
                continue;
            }

            $this->billing->applyPromotion($user, $period, [
                'trigger_event' => $triggerEvent,
            ]);
        }
    }

    /**
     * Active window, account-type/state targeting, and once-per-user-per-period.
     */
    private function isEligible(User $user, PromotionalPeriod $period): bool
    {
        if (! $period->isActive()) {
            return false;
        }

        $accountTypes = $period->target_account_types ?? [];
        if (! empty($accountTypes) && ! in_array($user->account_type, $accountTypes, true)) {
            return false;
        }

        $states = $period->target_states ?? [];
        if (! empty($states)) {
            $userState = $user->profile?->state_code;
            if (! $userState || ! in_array($userState, $states, true)) {
                return false;
            }
        }

        return ! PromotionClaim::on('billing')
            ->where('user_id', $user->id)
            ->where('promotion_period_id', $period->id)
            ->exists();
    }
}
