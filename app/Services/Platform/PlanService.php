<?php

namespace App\Services\Platform;

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
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AuditService $audit,
    ) {}

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
