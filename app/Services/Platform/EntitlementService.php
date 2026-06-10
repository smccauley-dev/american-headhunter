<?php

namespace App\Services\Platform;

use App\Models\Identity\User;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PromotionalPeriod;
use App\Services\BaseService;
use Illuminate\Support\Facades\DB;

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

    private function buildEntitlementList(User $user): array
    {
        // Phase 3 placeholder: resolve from default free plan for account type.
        // Phase 4 will query DB 4 (billing) subscriptions table for the user's active plan.
        $planKey = $this->defaultPlanKey($user->account_type);
        $plan    = MembershipPlan::on('platform')->where('plan_key', $planKey)->first();

        if (! $plan) {
            return ['enabled_keys' => [], 'limits' => [], 'values' => []];
        }

        $entitlements = FeatureEntitlement::on('platform')
            ->where('plan_id', $plan->id)
            ->get();

        return $this->parseEntitlements($entitlements);
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
