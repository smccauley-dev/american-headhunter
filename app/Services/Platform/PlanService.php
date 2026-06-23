<?php

namespace App\Services\Platform;

use App\Models\Billing\PromoCode;
use App\Models\Billing\Subscription;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanPromoCode;
use App\Models\Platform\PlanVersion;
use App\Models\Platform\PricingCallout;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Audit\AuditService;
use App\Services\BaseService;
use Illuminate\Support\Collection;

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
    private const CALLOUTS_CACHE_KEY = 'public_callouts';

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
        $this->invalidate(self::PRICING_CACHE_KEY, self::CALLOUTS_CACHE_KEY);
    }

    /**
     * Published pricing callouts (horizontal banners) grouped by account type, so
     * the pricing page can render them beneath the cards on the matching tab.
     * Cached 15 min in Valkey Cluster 2 — invalidate via flushPricingCache().
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function publicCallouts(): array
    {
        return $this->cache(self::CALLOUTS_CACHE_KEY, fn () => $this->buildPublicCallouts(), ttlMinutes: 15);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildPublicCallouts(): array
    {
        $groups = [];

        $callouts = PricingCallout::on('platform')
            ->where('is_published', true)
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->get();

        foreach ($callouts as $callout) {
            $groups[$callout->account_type][] = [
                'id'           => $callout->id,
                'eyebrow'      => $callout->eyebrow,
                'body'         => $callout->body,
                'features'     => array_values(array_map(static fn ($f): array => [
                    'label'       => $f['label'] ?? '',
                    'description' => $f['description'] ?? null,
                ], $callout->features ?? [])),
                'cta_label'    => $callout->cta_label,
                'cta_url'      => $callout->cta_url,
                'accent_color' => $callout->accent_color,
            ];
        }

        return $groups;
    }

    /**
     * Resolve a single public, active plan by key from the cached pricing payload,
     * reduced to what the signup flow needs. Returns null when the key is not a
     * currently-advertised plan, so a stale or hand-edited ?plan param is ignored.
     *
     * @return array{plan_key: string, display_name: string, account_type: string, is_paid: bool, monthly_price_cents: int, annual_price_cents: int}|null
     */
    public function findPublicPlan(string $planKey): ?array
    {
        foreach ($this->publicPricing() as $accountType => $plans) {
            foreach ($plans as $plan) {
                if ($plan['plan_key'] !== $planKey) {
                    continue;
                }

                $isPaid = ! $plan['is_default_free']
                    && (($plan['monthly_price_cents'] ?? 0) > 0 || ($plan['annual_price_cents'] ?? 0) > 0);

                return [
                    'plan_key'            => $plan['plan_key'],
                    'display_name'        => $plan['display_name'],
                    'account_type'        => $accountType,
                    'is_paid'             => $isPaid,
                    'monthly_price_cents' => (int) ($plan['monthly_price_cents'] ?? 0),
                    'annual_price_cents'  => (int) ($plan['annual_price_cents'] ?? 0),
                ];
            }
        }

        return null;
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

        // Promo codes advertised on each plan's pricing card. Assembled in PHP
        // across DB 12 (links + periods) and DB 4 (codes) — never joined. Only
        // currently-valid codes whose period is active are shown.
        $links = PlanPromoCode::on('platform')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->where('show_on_pricing_card', true)
            ->get()
            ->groupBy('plan_id');

        $codes = PromoCode::on('billing')
            ->whereIn('id', $links->flatten()->pluck('promo_code_id')->unique())
            ->get()
            ->keyBy('id');

        $periods = PromotionalPeriod::on('platform')
            ->whereIn('id', $codes->pluck('promotional_period_id')->unique())
            ->get()
            ->keyBy('id');

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
                'promo_codes'         => $this->pricingPromoCodes(
                    $links->get($plan->id, collect()),
                    $codes,
                    $periods,
                ),
            ];
        }

        return $groups;
    }

    /**
     * Shape the pricing-card promo codes for a plan, keeping only codes that are
     * currently redeemable and whose promotional period is active.
     *
     * @param  Collection<int,PlanPromoCode>      $links
     * @param  Collection<string,PromoCode>       $codes
     * @param  Collection<string,PromotionalPeriod> $periods
     * @return array<int,array<string,?string>>
     */
    private function pricingPromoCodes(Collection $links, Collection $codes, Collection $periods): array
    {
        $out = [];

        foreach ($links as $link) {
            $code = $codes->get($link->promo_code_id);
            if (! $code || ! $code->is_active) {
                continue;
            }
            if ($code->starts_at && $code->starts_at->isFuture()) {
                continue;
            }
            if ($code->expires_at && $code->expires_at->isPast()) {
                continue;
            }
            if (! is_null($code->max_redemptions) && $code->redemption_count >= $code->max_redemptions) {
                continue;
            }

            $period = $periods->get($code->promotional_period_id);
            if (! $period || ! $period->isActive()) {
                continue;
            }

            $out[] = [
                'code'             => $code->code,
                'label'            => $period->display_name,
                'discount_summary' => $this->discountSummary($period),
            ];
        }

        return $out;
    }

    /**
     * A short human label for a period's monetary discount, or null for none.
     */
    private function discountSummary(PromotionalPeriod $period): ?string
    {
        if ($period->discount_percentage && $period->discount_percentage > 0) {
            $pct = rtrim(rtrim(number_format((float) $period->discount_percentage, 2), '0'), '.');

            return "{$pct}% off";
        }

        if ($period->discount_amount_cents && $period->discount_amount_cents > 0) {
            return '$' . number_format($period->discount_amount_cents / 100, 2) . ' off';
        }

        return null;
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
