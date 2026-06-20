<?php

namespace App\Services\Platform;

use App\Models\Billing\Subscription;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\Audit\AuditService;
use App\Services\BaseService;

/**
 * Plan version management for DB 12 membership plans.
 *
 * A plan's live row (membership_plans) is the staged definition; new
 * subscriptions lock to the highest-numbered plan_version snapshot, and
 * plan_versions are immutable (Postgres RULE blocks UPDATE). Publishing a new
 * version is therefore the only way to change pricing/entitlements for new
 * subscribers — existing subscribers keep their locked version (grandfathering).
 */
class PlanService extends BaseService
{
    private const PRICING_CACHE_KEY = 'public_pricing';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

    /**
     * Assemble the public pricing page payload: every public, active plan grouped
     * by account type, each with the price from its current published version
     * (falling back to the staged price when no version is published yet) and the
     * perks marked show_on_pricing, ordered for display. Cached 15 min in Valkey
     * Cluster 2 — invalidate via flushPricingCache() on any plan/version edit.
     */
    public function publicPricing(): array
    {
        return $this->cache(self::PRICING_CACHE_KEY, fn () => $this->buildPublicPricing(), ttlMinutes: 15);
    }

    public function flushPricingCache(): void
    {
        $this->invalidate(self::PRICING_CACHE_KEY);
    }

    /**
     * Whether any live subscription is locked to one of this plan's versions.
     * Cross-DB: subscriptions live in DB 4 (billing) and reference plan_versions
     * in DB 12 (platform) by UUID — assembled here, never via an Eloquent join.
     */
    public function hasActiveSubscriptions(MembershipPlan $plan): bool
    {
        $versionIds = PlanVersion::on('platform')
            ->where('plan_id', $plan->id)
            ->pluck('id')
            ->all();

        if ($versionIds === []) {
            return false;
        }

        return Subscription::on('billing')
            ->whereIn('plan_version_id', $versionIds)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->exists();
    }

    /**
     * Soft-delete a plan: it stays referenced by its immutable versions and any
     * historical subscriptions, so we only hide it (deleted_at) and stamp
     * deprecated_at. Refuses while live subscriptions exist — callers must gate on
     * hasActiveSubscriptions() first; this re-checks to close the TOCTOU window.
     */
    public function softDeletePlan(MembershipPlan $plan, string $actorUserId): bool
    {
        if ($this->hasActiveSubscriptions($plan)) {
            return false;
        }

        // deprecated_at is not mass-assignable here — set it directly, then let
        // Eloquent's soft delete stamp deleted_at via delete().
        $plan->deprecated_at = now();
        $plan->save();
        $plan->delete();

        $this->flushPricingCache();

        $this->audit->log(
            eventType:      'membership_plan.deleted',
            sourceDatabase: 'ah_platform',
            tableName:      'membership_plans',
            recordId:       $plan->id,
            userId:         $actorUserId,
            actionSummary:  "Soft-deleted plan {$plan->plan_key}",
            newValues:      ['plan_key' => $plan->plan_key],
        );

        return true;
    }

    private function buildPublicPricing(): array
    {
        $plans = MembershipPlan::on('platform')
            ->where('is_public', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->with(['currentVersion', 'entitlements' => fn ($q) => $q->where('show_on_pricing', true)])
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->get();

        $groups = [];

        foreach ($plans as $plan) {
            $version = $plan->currentVersion;

            $groups[$plan->account_type][] = [
                'id'                  => $plan->id,
                'plan_key'            => $plan->plan_key,
                'display_name'        => $plan->display_name,
                'tagline'             => $plan->tagline,
                'description'         => $plan->description,
                'monthly_price_cents' => $version->monthly_price_cents ?? $plan->monthly_price_cents,
                'annual_price_cents'  => $version->annual_price_cents ?? $plan->annual_price_cents,
                'monthly_enabled'     => (bool) $plan->monthly_enabled,
                'annual_enabled'      => (bool) $plan->annual_enabled,
                'is_default_free'     => (bool) $plan->is_default_free,
                'header_image_path'   => $plan->header_image_path,
                'accent_color'        => $plan->accent_color,
                'badge_label'         => $plan->badge_label,
                'is_featured'         => (bool) $plan->is_featured,
                'perks'               => $plan->entitlements->map(fn (FeatureEntitlement $e) => [
                    'label'       => $e->display_label ?: $e->feature_key,
                    'description' => $e->display_description,
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * Snapshot the plan's current pricing and feature entitlements into a new
     * immutable plan_versions row, then flush the entitlement cache so new
     * resolutions pick it up.
     */
    public function publishNewVersion(MembershipPlan $plan, string $actorUserId, ?string $reason = null): PlanVersion
    {
        $nextNumber = (int) PlanVersion::on('platform')
            ->where('plan_id', $plan->id)
            ->max('version_number') + 1;

        $version = PlanVersion::on('platform')->create([
            'plan_id'                 => $plan->id,
            'version_number'          => $nextNumber,
            'plan_key'                => $plan->plan_key,
            'display_name'            => $plan->display_name,
            'monthly_price_cents'     => $plan->monthly_price_cents,
            'annual_price_cents'      => $plan->annual_price_cents,
            'platform_fee_pct'        => $plan->platform_fee_pct,
            'commission_pct'          => $plan->commission_pct,
            'stripe_price_id_monthly' => $plan->stripe_monthly_price_id,
            'stripe_price_id_annual'  => $plan->stripe_annual_price_id,
            'entitlements_snapshot'   => $this->buildSnapshot($plan->id),
            'change_reason'           => $reason ?: "Version {$nextNumber}",
            'created_by_user_id'      => $actorUserId,
        ]);

        $this->entitlements->invalidateAll();
        $this->flushPricingCache();

        $this->audit->log(
            eventType:      'plan_version.published',
            sourceDatabase: 'ah_platform',
            tableName:      'plan_versions',
            recordId:       $version->id,
            userId:         $actorUserId,
            actionSummary:  "Published v{$nextNumber} of plan {$plan->plan_key}",
            newValues:      [
                'plan_id'             => $plan->id,
                'version_number'      => $nextNumber,
                'monthly_price_cents' => $plan->monthly_price_cents,
                'annual_price_cents'  => $plan->annual_price_cents,
            ],
        );

        return $version;
    }

    /**
     * Build the entitlements_snapshot map from the plan's live feature
     * entitlements, in the shape EntitlementService::parseSnapshot() expects:
     *   { feature_key: { type: <feature_type>, value: <typed value> } }
     */
    private function buildSnapshot(string $planId): array
    {
        $snapshot = [];

        foreach (FeatureEntitlement::on('platform')->where('plan_id', $planId)->get() as $e) {
            $snapshot[$e->feature_key] = [
                'type'  => $e->feature_type,
                'value' => $e->value(),
            ];
        }

        return $snapshot;
    }
}
