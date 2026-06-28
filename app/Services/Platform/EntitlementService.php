<?php

namespace App\Services\Platform;

use App\Models\Billing\PromotionClaim;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\BaseService;
use App\Support\Entitlements;

class EntitlementService extends BaseService
{
    /**
     * Check if a user can access a named feature.
     *
     * Usage:
     *   app(EntitlementService::class)->can($user, 'trail_camera_integration')
     *
     * Feature keys correspond to feature_entitlements.feature_key in DB 12.
     * Never compare plan names directly in application code — always use this method.
     */
    public function can(User $user, string $featureKey): bool
    {
        $entitlements = $this->getUserEntitlements($user);
        return in_array($featureKey, $entitlements['enabled_keys'] ?? [], true);
    }

    /**
     * Get the integer limit for a feature (e.g., saved_searches_limit).
     * Returns -1 for unlimited, 0 if not available, or the configured limit.
     */
    public function limit(User $user, string $featureKey): int
    {
        $entitlements = $this->getUserEntitlements($user);
        return $entitlements['limits'][$featureKey] ?? 0;
    }

    /**
     * Get the string value for a feature (e.g., trust_badge_level).
     */
    public function value(User $user, string $featureKey): ?string
    {
        $entitlements = $this->getUserEntitlements($user);
        return $entitlements['values'][$featureKey] ?? null;
    }

    /**
     * The single state a hunter is locked to, or null if they are unrestricted.
     *
     * When the single_state_hunt entitlement is active, hunting is limited to the
     * hunter's ORIGINAL residence state (user_profiles.original_state_code) — the
     * first state ever recorded, which never changes even if they later edit their
     * home state. Returns null when the entitlement is off, or when no original
     * state has been recorded yet (cannot restrict to an unknown state).
     *
     * The multi_state_hunt entitlement always wins: a membership that includes it
     * lets the hunter hunt any state, overriding single_state_hunt entirely.
     */
    public function restrictedHuntState(User $user): ?string
    {
        if ($this->can($user, Entitlements::MULTI_STATE_HUNT)) {
            return null;
        }

        if (! $this->can($user, Entitlements::SINGLE_STATE_HUNT)) {
            return null;
        }

        return $user->profile?->original_state_code;
    }

    /**
     * Whether a single-state-restricted hunter may hunt in the given state.
     * Unrestricted hunters (entitlement off, or no recorded original state) may
     * hunt anywhere. Comparison is case-insensitive on the two-letter code.
     */
    public function canHuntInState(User $user, string $stateCode): bool
    {
        $allowed = $this->restrictedHuntState($user);

        return $allowed === null || strcasecmp($allowed, $stateCode) === 0;
    }

    /**
     * Resolve the membership a user is currently on — the plan identity behind
     * their entitlements, for display on the profile / account pages.
     *
     * Mirrors buildEntitlementList()'s precedence (promotion claim → active
     * subscription → default free tier) but returns the plan's identity and
     * billing state rather than its feature list. Prices come from the locked
     * plan VERSION for paying/promo members (grandfathered) and from the live
     * plan for free-tier members.
     *
     * @return array{
     *   plan_key:string, display_name:string, tagline:?string, account_type:string,
     *   accent_color:?string, is_free:bool, source:string, status:string,
     *   status_label:string, monthly_price:?string, annual_price:?string,
     *   currency:string, renews_at:?string, trial_ends_at:?string, cancelled_at:?string
     * }
     */
    public function currentMembership(User $user): array
    {
        $claim = $this->activePromotionClaim($user->id);
        if ($claim && $claim->granted_plan_version_id) {
            $membership = $this->membershipFromVersion($claim->granted_plan_version_id);
            if ($membership !== null) {
                return array_merge($membership, [
                    'source'       => 'promotion',
                    'status'       => 'promo',
                    'status_label' => 'Promotional',
                    'renews_at'    => $this->formatMembershipDate($claim->expires_at),
                ]);
            }
        }

        $subscription = $this->activeSubscription($user->id);
        if ($subscription && $subscription->plan_version_id) {
            $membership = $this->membershipFromVersion($subscription->plan_version_id);
            if ($membership !== null) {
                return array_merge($membership, [
                    'source'           => 'subscription',
                    'status'           => $subscription->status,
                    'status_label'     => match ($subscription->status) {
                        'trialing' => 'Trial',
                        'past_due' => 'Past Due',
                        default    => 'Active',
                    },
                    'billing_interval' => $subscription->billing_interval,
                    'renews_at'        => $this->formatMembershipDate($subscription->current_period_end),
                    'trial_ends_at'    => $this->formatMembershipDate($subscription->trial_ends_at),
                    'cancelled_at'     => $this->formatMembershipDate($subscription->cancelled_at),
                ]);
            }
        }

        return $this->freeTierMembership($user->account_type);
    }

    /**
     * Build the membership identity from a locked plan version (paying / promo
     * members), joining to the parent plan for the tagline, account type, and
     * accent color the version does not carry. Returns null if the version is gone.
     */
    private function membershipFromVersion(string $planVersionId): ?array
    {
        $version = PlanVersion::on('platform')->find($planVersionId);
        if (! $version) {
            return null;
        }

        $plan = MembershipPlan::on('platform')->find($version->plan_id);

        // Identity (name/key) follows the LIVE plan — a rename is branding, not a
        // pricing change — while price stays grandfathered to the locked version.
        return [
            'plan_key'      => $plan?->plan_key ?? $version->plan_key,
            'display_name'  => $plan?->display_name ?? $version->display_name,
            'tagline'       => $plan?->tagline,
            'account_type'  => $plan?->account_type ?? '',
            'accent_color'  => $plan?->accent_color,
            'is_free'       => (int) $version->monthly_price_cents === 0 && (int) $version->annual_price_cents === 0,
            'monthly_price'    => $this->formatMoney($version->monthly_price_cents),
            'annual_price'     => $this->formatMoney($version->annual_price_cents),
            'currency'         => $plan?->currency ?? 'USD',
            'billing_interval' => null,
            'trial_ends_at'    => null,
            'cancelled_at'     => null,
        ];
    }

    /**
     * The default free-tier membership for an account type — used when a user has
     * no active subscription or promotion. Reads the live plan (not a version)
     * since free-tier members are never grandfathered to a locked version.
     */
    private function freeTierMembership(string $accountType): array
    {
        $plan = MembershipPlan::on('platform')
            ->where('plan_key', $this->defaultPlanKey($accountType))
            ->first();

        return [
            'plan_key'      => $plan?->plan_key ?? $this->defaultPlanKey($accountType),
            'display_name'  => $plan?->display_name ?? 'Free',
            'tagline'       => $plan?->tagline,
            'account_type'  => $accountType,
            'accent_color'  => $plan?->accent_color,
            'is_free'       => true,
            'source'        => 'free',
            'status'        => 'free',
            'status_label'  => 'Free',
            'monthly_price' => $this->formatMoney($plan?->monthly_price_cents ?? 0),
            'annual_price'  => $this->formatMoney($plan?->annual_price_cents ?? 0),
            'currency'      => $plan?->currency ?? 'USD',
            'renews_at'     => null,
            'trial_ends_at' => null,
            'cancelled_at'  => null,
        ];
    }

    private function formatMoney(?int $cents): ?string
    {
        if ($cents === null) {
            return null;
        }

        return number_format($cents / 100, 2);
    }

    private function formatMembershipDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($value)->format('M j, Y');
    }

    /**
     * Invalidate a user's entitlement cache.
     * Call whenever: subscription changes, promo activates/expires, plan version updates.
     */
    public function invalidateForUser(string $userId): void
    {
        $this->invalidate("user_entitlements:{$userId}");
    }

    /**
     * Invalidate every user's entitlement cache.
     * Call when a change affects many users at once: plan version published,
     * a plan's feature entitlements edited, or a promotion's terms changed.
     * The leading wildcard matches Laravel's cache prefix on the key.
     */
    public function invalidateAll(): void
    {
        $this->invalidatePattern('*user_entitlements:*');
    }

    private function getUserEntitlements(User $user): array
    {
        return $this->cache("user_entitlements:{$user->id}", function () use ($user) {
            return $this->buildEntitlementList($user);
        }, ttlMinutes: 5);
    }

    /**
     * Resolve a user's entitlements in precedence order:
     *   1. an active promotion claim's granted plan version (highest precedence)
     *   2. the user's active subscription's locked plan version
     *   3. the default free plan for the account type
     *
     * Subscription/promotion records live in DB 4 (billing); plan versions and
     * entitlements live in DB 12 (platform). This is service-layer cross-DB
     * assembly — no Eloquent relationship crosses the connection boundary.
     */
    private function buildEntitlementList(User $user): array
    {
        $claim = $this->activePromotionClaim($user->id);
        if ($claim && $claim->granted_plan_version_id) {
            $resolved = $this->entitlementsForVersion($claim->granted_plan_version_id);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $subscription = $this->activeSubscription($user->id);
        if ($subscription && $subscription->plan_version_id) {
            $resolved = $this->entitlementsForVersion($subscription->plan_version_id);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->freeTierEntitlements($user->account_type);
    }

    /**
     * Entitlements for a specific plan version. Prefers the immutable snapshot
     * (grandfathered), falling back to the plan's live entitlements only if the
     * snapshot is missing. Returns null if the version no longer exists.
     */
    private function entitlementsForVersion(string $planVersionId): ?array
    {
        $version = PlanVersion::on('platform')->find($planVersionId);
        if (! $version) {
            return null;
        }

        $snapshot = $version->entitlements_snapshot ?? [];
        if (! empty($snapshot)) {
            return $this->parseSnapshot($snapshot);
        }

        return $this->entitlementsForPlan($version->plan_id);
    }

    private function freeTierEntitlements(string $accountType): array
    {
        $plan = MembershipPlan::on('platform')
            ->where('plan_key', $this->defaultPlanKey($accountType))
            ->first();

        if (! $plan) {
            return ['enabled_keys' => [], 'limits' => [], 'values' => []];
        }

        return $this->entitlementsForPlan($plan->id);
    }

    private function entitlementsForPlan(string $planId): array
    {
        $entitlements = FeatureEntitlement::on('platform')
            ->where('plan_id', $planId)
            ->get();

        return $this->parseEntitlements($entitlements);
    }

    private function activeSubscription(string $userId): ?Subscription
    {
        return Subscription::on('billing')
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->first();
    }

    /**
     * Public accessor for a user's active subscription — used by callers that need
     * the Stripe identifiers (e.g. surfacing the live discount on the membership
     * card). currentMembership() deliberately returns only the locked list price,
     * so the Stripe lookup lives in the controller, not here.
     */
    public function activeSubscriptionFor(User $user): ?Subscription
    {
        return $this->activeSubscription($user->id);
    }

    private function activePromotionClaim(string $userId): ?PromotionClaim
    {
        return PromotionClaim::on('billing')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('activated_at')
            ->first();
    }

    /**
     * Parse a plan_versions.entitlements_snapshot map into the resolved shape.
     * Each entry is { "type": ..., "value": ... } keyed by feature_key.
     */
    private function parseSnapshot(array $snapshot): array
    {
        $enabledKeys = [];
        $limits      = [];
        $values      = [];

        foreach ($snapshot as $featureKey => $entry) {
            $type  = $entry['type']  ?? null;
            $value = $entry['value'] ?? null;

            match ($type) {
                'boolean' => $value ? ($enabledKeys[] = $featureKey) : null,
                'integer' => $limits[$featureKey] = (int) $value,
                'string'  => $values[$featureKey] = $value,
                'json'    => $values[$featureKey] = $value,
                default   => null,
            };
        }

        return ['enabled_keys' => $this->expandImplied($enabledKeys), 'limits' => $limits, 'values' => $values];
    }

    private function parseEntitlements(\Illuminate\Database\Eloquent\Collection $entitlements): array
    {
        $enabledKeys = [];
        $limits      = [];
        $values      = [];

        foreach ($entitlements as $e) {
            match ($e->feature_type) {
                'boolean' => $e->bool_value ? ($enabledKeys[] = $e->feature_key) : null,
                'integer' => $limits[$e->feature_key] = (int) $e->int_value,
                'string'  => $values[$e->feature_key] = $e->string_value,
                'json'    => $values[$e->feature_key] = $e->json_value,
                default   => null,
            };
        }

        return ['enabled_keys' => $this->expandImplied($enabledKeys), 'limits' => $limits, 'values' => $values];
    }

    /**
     * Expand a set of enabled feature keys with their transitive implications
     * (see Entitlements::IMPLIES). Granting a superset key (e.g. shared_trail_cams)
     * yields the keys it implies (trail_camera_integration) without both being
     * seeded on the plan. Order-independent; returns a de-duplicated list.
     *
     * @param  string[]  $enabledKeys
     * @return string[]
     */
    private function expandImplied(array $enabledKeys): array
    {
        $resolved = [];
        $stack    = $enabledKeys;

        while ($stack !== []) {
            $key = array_pop($stack);
            if (isset($resolved[$key])) {
                continue;
            }
            $resolved[$key] = true;

            foreach (Entitlements::IMPLIES[$key] ?? [] as $implied) {
                if (! isset($resolved[$implied])) {
                    $stack[] = $implied;
                }
            }
        }

        return array_keys($resolved);
    }

    private function defaultPlanKey(string $accountType): string
    {
        return match ($accountType) {
            'hunter'     => 'hunter_scout',
            'landowner'  => 'landowner_homestead',
            'club'       => 'club_basic',
            'outfitter'  => 'outfitter_standard',
            'consultant' => 'consultant_basic',
            'seller'     => 'seller_standard',
            default      => 'hunter_scout',
        };
    }
}
