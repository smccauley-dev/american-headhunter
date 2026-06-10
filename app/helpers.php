<?php

use App\Services\Platform\FeatureFlagService;

if (! function_exists('feature')) {
    /**
     * Check whether a platform feature flag is enabled.
     *
     * This checks platform-wide flag status — it does NOT check per-user subscription
     * entitlements. For per-user entitlement checks, use EntitlementService::can().
     *
     * Often both checks are needed:
     *   if (feature('auction_module') && $entitlements->can($user, 'auction_module')) { ... }
     *
     * @param  string                       $flagKey  The feature flag key (e.g. 'auction_module')
     * @param  \App\Models\Identity\User|null $user   Optional user for per-user rollout/overrides
     */
    function feature(string $flagKey, ?\App\Models\Identity\User $user = null): bool
    {
        return app(FeatureFlagService::class)->isEnabled($flagKey, $user);
    }
}
