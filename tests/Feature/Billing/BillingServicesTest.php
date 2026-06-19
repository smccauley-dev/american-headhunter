<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\PromotionalPeriod;
use App\Services\Billing\BillingService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.2 Billing Services — SubscriptionService, BillingService, and the
 * EntitlementService snapshot-resolution rewire.
 *
 * Runs against the real `billing` and `platform` PostgreSQL connections (the
 * services declare those connections explicitly), so no DatabaseTransactions —
 * rows are force-deleted in tearDown and the entitlement cache is invalidated.
 *
 * Entitlement-resolution tests use controlled, superseded plan_versions
 * (hidden from currentVersionForPlan) locked directly via SubscriptionService::start.
 */
class BillingServicesTest extends TestCase
{
    /** @var array<int,string> */ private array $subscriptionIds = [];
    /** @var array<int,string> */ private array $claimIds = [];
    /** @var array<int,string> */ private array $versionIds = [];
    /** @var array<int,string> */ private array $promoIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    private string $scoutPlanId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoutPlanId = DB::connection('platform')->table('membership_plans')
            ->where('plan_key', 'hunter_scout')->value('id');
    }

    protected function tearDown(): void
    {
        $billing  = DB::connection('billing');
        $platform = DB::connection('platform');

        if ($this->claimIds)        { $billing->table('promotion_claims')->whereIn('id', $this->claimIds)->delete(); }
        if ($this->subscriptionIds) { $billing->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete(); }
        if ($this->versionIds)      { $platform->table('plan_versions')->whereIn('id', $this->versionIds)->delete(); }
        if ($this->promoIds)        { $platform->table('promotional_periods')->whereIn('id', $this->promoIds)->delete(); }

        $entitlements = app(EntitlementService::class);
        foreach ($this->userIds as $uid) {
            $entitlements->invalidateForUser($uid);
        }

        try { $billing->disconnect(); } catch (\Throwable) {}
        try { $platform->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function subscriptions(): SubscriptionService { return app(SubscriptionService::class); }
    private function billing(): BillingService { return app(BillingService::class); }
    private function entitlements(): EntitlementService { return app(EntitlementService::class); }

    private function makeUser(string $accountType = 'hunter'): User
    {
        $u = new User();
        $u->id = (string) Str::uuid();
        $u->account_type = $accountType;
        $this->userIds[] = $u->id;

        return $u;
    }

    /** Insert a controlled, superseded plan version (invisible to currentVersionForPlan). */
    private function makeVersion(array $snapshot): string
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('plan_versions')->insert([
            'id'                    => $id,
            'plan_id'               => $this->scoutPlanId,
            'version_number'        => random_int(100000, 999999),
            'plan_key'              => 'hunter_scout',
            'display_name'          => 'Test Version',
            'monthly_price_cents'   => 0,
            'annual_price_cents'    => 0,
            'entitlements_snapshot' => json_encode($snapshot),
            'superseded_at'         => now(),
            'effective_from'        => now(),
            'created_at'            => now(),
        ]);
        $this->versionIds[] = $id;

        return $id;
    }

    private function makePromo(): PromotionalPeriod
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('promotional_periods')->insert([
            'id'             => $id,
            'promo_key'      => 'test_promo_' . Str::random(8),
            'display_name'   => 'Test Promo',
            'promotion_type' => 'tier_grant',
            'status'         => 'active',
        ]);
        $this->promoIds[] = $id;

        return PromotionalPeriod::on('platform')->find($id);
    }

    private function trackSub(Subscription $s): Subscription
    {
        $this->subscriptionIds[] = $s->id;

        return $s;
    }

    public function test_current_version_for_plan_returns_non_superseded(): void
    {
        $version = $this->subscriptions()->currentVersionForPlan('hunter_scout');

        $this->assertSame('hunter_scout', $version->plan_key);
        $this->assertNull($version->superseded_at, 'current version must not be superseded');
    }

    public function test_subscribe_locks_current_version_and_is_active(): void
    {
        $user    = $this->makeUser('hunter');
        $current = $this->subscriptions()->currentVersionForPlan('hunter_scout');

        $sub = $this->trackSub($this->billing()->subscribe($user, 'hunter_scout'));

        $this->assertTrue(Str::isUuid($sub->id));
        $this->assertSame('active', $sub->status);
        $this->assertSame($current->id, $sub->plan_version_id, 'subscription locks the plan current version');
        $this->assertNotNull($sub->current_period_start);
        $this->assertNotNull($sub->current_period_end);
    }

    public function test_cannot_hold_two_active_subscriptions(): void
    {
        $user = $this->makeUser('hunter');
        $this->trackSub($this->billing()->subscribe($user, 'hunter_scout'));

        $this->expectException(\RuntimeException::class);
        $this->billing()->subscribe($user, 'hunter_scout');
    }

    public function test_trial_sets_trialing_status_and_period_to_trial_end(): void
    {
        $user = $this->makeUser('hunter');

        $sub = $this->trackSub($this->billing()->subscribe($user, 'hunter_scout', ['trial_days' => 14]));

        $this->assertSame('trialing', $sub->status);
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertSame(
            now()->startOfDay()->addDays(14)->toDateString(),
            $sub->current_period_end->toDateString(),
        );
    }

    public function test_cancel_frees_the_active_slot(): void
    {
        $user = $this->makeUser('hunter');
        $this->trackSub($this->billing()->subscribe($user, 'hunter_scout'));

        $cancelled = $this->billing()->cancel($user);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->fresh()->cancelled_at);
        $this->assertNull($this->subscriptions()->activeFor($user->id));

        // Slot is free — a new subscription can be created.
        $sub2 = $this->trackSub($this->billing()->subscribe($user, 'hunter_scout'));
        $this->assertSame('active', $sub2->status);
    }

    public function test_change_plan_swaps_to_new_plan_current_version(): void
    {
        $user = $this->makeUser('hunter');
        $this->trackSub($this->billing()->subscribe($user, 'hunter_scout'));

        $sportsman = $this->subscriptions()->currentVersionForPlan('hunter_sportsman');
        $changed   = $this->billing()->changePlan($user, 'hunter_sportsman');

        $this->assertSame($sportsman->id, $changed->plan_version_id);
        $this->assertSame($this->subscriptions()->activeFor($user->id)->id, $changed->id, 'changePlan keeps the same row');
    }

    public function test_entitlements_resolve_from_subscription_snapshot(): void
    {
        $user      = $this->makeUser('hunter');
        $versionId = $this->makeVersion([
            'trail_camera_integration' => ['type' => 'boolean', 'value' => true],
            'saved_searches_limit'     => ['type' => 'integer', 'value' => 25],
            'trust_badge_level'        => ['type' => 'string',  'value' => 'gold'],
        ]);

        $this->trackSub($this->subscriptions()->start($user->id, $versionId));

        $this->assertTrue($this->entitlements()->can($user, 'trail_camera_integration'));
        $this->assertSame(25, $this->entitlements()->limit($user, 'saved_searches_limit'));
        $this->assertSame('gold', $this->entitlements()->value($user, 'trust_badge_level'));
    }

    public function test_active_promotion_claim_overrides_subscription(): void
    {
        $user         = $this->makeUser('hunter');
        $subVersion   = $this->makeVersion(['trail_camera_integration' => ['type' => 'boolean', 'value' => false]]);
        $promoVersion = $this->makeVersion(['trail_camera_integration' => ['type' => 'boolean', 'value' => true]]);

        $this->trackSub($this->subscriptions()->start($user->id, $subVersion));
        $this->assertFalse($this->entitlements()->can($user, 'trail_camera_integration'));

        $claim = $this->billing()->applyPromotion($user, $this->makePromo(), [
            'granted_plan_version_id' => $promoVersion,
        ]);
        $this->claimIds[] = $claim->id;

        $this->assertSame('active', $claim->status);
        $this->assertTrue(
            $this->entitlements()->can($user, 'trail_camera_integration'),
            'promotion claim version takes precedence over the subscription version',
        );
    }

    public function test_user_without_subscription_gets_free_tier(): void
    {
        $user = $this->makeUser('hunter');

        // Free-tier path reads live feature_entitlements for the default plan.
        $expected = (int) DB::connection('platform')->table('feature_entitlements')
            ->where('plan_id', $this->scoutPlanId)
            ->where('feature_key', 'saved_searches_limit')
            ->value('int_value');

        $this->assertSame($expected, $this->entitlements()->limit($user, 'saved_searches_limit'));
    }

    public function test_current_membership_falls_back_to_free_tier(): void
    {
        $user = $this->makeUser('hunter');

        $membership = $this->entitlements()->currentMembership($user);

        $this->assertSame('hunter_scout', $membership['plan_key']);
        $this->assertSame('free', $membership['source']);
        $this->assertSame('free', $membership['status']);
        $this->assertTrue($membership['is_free']);
        $this->assertNull($membership['renews_at']);
    }

    public function test_current_membership_uses_locked_price_but_live_name(): void
    {
        $user      = $this->makeUser('hunter');
        // Locked version carries a stale name + its own price; identity should come
        // from the live plan (Scout) while the price stays grandfathered.
        $versionId = (string) Str::uuid();
        DB::connection('platform')->table('plan_versions')->insert([
            'id'                    => $versionId,
            'plan_id'               => $this->scoutPlanId,
            'version_number'        => random_int(100000, 999999),
            'plan_key'              => 'hunter_legacy',
            'display_name'          => 'Legacy Name',
            'monthly_price_cents'   => 1299,
            'annual_price_cents'    => 0,
            'entitlements_snapshot' => json_encode([]),
            'superseded_at'         => now(),
            'effective_from'        => now(),
            'created_at'            => now(),
        ]);
        $this->versionIds[] = $versionId;

        $this->trackSub($this->subscriptions()->start($user->id, $versionId));

        $membership = $this->entitlements()->currentMembership($user);

        $this->assertSame('subscription', $membership['source']);
        $this->assertSame('active', $membership['status']);
        $this->assertSame('hunter_scout', $membership['plan_key'], 'identity follows the live plan, not the locked version');
        $this->assertSame('12.99', $membership['monthly_price'], 'price stays grandfathered to the version');
        $this->assertFalse($membership['is_free']);
        $this->assertNotNull($membership['renews_at']);
    }
}
