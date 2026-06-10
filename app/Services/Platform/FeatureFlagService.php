<?php

namespace App\Services\Platform;

use App\Models\Identity\User;
use App\Models\Platform\FeatureFlag;
use App\Services\BaseService;

class FeatureFlagService extends BaseService
{
    /**
     * Determine whether a feature flag is enabled for an optional user.
     *
     * Two-tier evaluation:
     *  1. Flag must be globally enabled (is_enabled = true).
     *  2. If rollout_percentage < 100, user is assigned a deterministic bucket via CRC32.
     *     Per-user overrides in enabled_for_user_ids bypass the percentage gate.
     */
    public function isEnabled(string $flagKey, ?User $user = null): bool
    {
        $cacheKey = 'feature_flag:' . $flagKey . ':' . ($user?->id ?? 'guest');

        return $this->cache($cacheKey, function () use ($flagKey, $user) {
            $flag = FeatureFlag::on('platform')->where('key', $flagKey)->first();

            if (! $flag || ! $flag->is_enabled) {
                return false;
            }

            // No user context — global on/off check only (Blade templates, server-side gates)
            if (! $user) {
                return true;
            }

            // Per-user allow-list bypasses rollout percentage
            if (! empty($flag->enabled_for_user_ids) && in_array($user->id, $flag->enabled_for_user_ids, true)) {
                return true;
            }

            // rollout_percentage = 0 means not yet rolled out; only allow-list above can access
            if ($flag->rollout_percentage <= 0) {
                return false;
            }

            if ($flag->rollout_percentage >= 100) {
                return true;
            }

            // Deterministic bucket assignment — same user always gets the same result
            $bucket = abs(crc32($user->id . $flagKey)) % 100;
            return $bucket < $flag->rollout_percentage;
        }, ttlMinutes: 5);
    }

    /**
     * Invalidate the flag cache for a specific flag (all users).
     * Call after updating any feature_flag row.
     */
    public function invalidateFlag(string $flagKey): void
    {
        $this->invalidatePattern('feature_flag:' . $flagKey . ':*');
    }

    /**
     * Get all feature flags with their current state.
     * Used by the admin backend — not for per-request gating.
     */
    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        return FeatureFlag::on('platform')->orderBy('key')->get();
    }
}
