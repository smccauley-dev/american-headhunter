<?php

namespace App\Services\Platform;

use App\Models\Billing\PromotionClaim;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\BaseService;

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
     * Invalidate a user's entitlement cache.
     * Call whenever: subscription changes, promo activates/expires, plan version updates.
     */
    public function invalidateForUser(string $userId): void
    {
        $this->invalidate("user_entitlements:{$userId}");
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

        return ['enabled_keys' => $enabledKeys, 'limits' => $limits, 'values' => $values];
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

        return ['enabled_keys' => $enabledKeys, 'limits' => $limits, 'values' => $values];
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
