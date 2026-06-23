<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Models\Platform\FeatureEntitlement;
use App\Models\Platform\MembershipPlan;
use App\Models\Platform\PlanVersion;
use App\Services\Platform\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.6 — PlanService::publishNewVersion().
 *
 * Runs against the real `platform` connection (no DatabaseTransactions); the
 * throwaway plan, its entitlements, and versions are removed in tearDown.
 * plan_versions cannot be UPDATEd (Postgres RULE) but can be DELETEd, so
 * cleanup works.
 */
class PlanServiceTest extends TestCase
{
    private string $planId;
    private string $planKey;

    /** @var string[] subscription ids created in billing during a test */
    private array $subscriptionIds = [];

    /** @var string[] pricing_callout ids created in platform during a test */
    private array $calloutIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->planKey = 'test_plan_' . Str::random(8);

        $plan = MembershipPlan::on('platform')->create([
            'plan_key'            => $this->planKey,
            'account_type'        => 'hunter',
            'display_name'        => 'Test Plan',
            'monthly_price_cents' => 1500,
            'annual_price_cents'  => 15000,
            'platform_fee_pct'    => 5.00,
        ]);
        $this->planId = $plan->id;

        FeatureEntitlement::on('platform')->create([
            'plan_id'      => $this->planId,
            'feature_key'  => 'trail_camera_integration',
            'feature_type' => 'boolean',
            'bool_value'   => true,
        ]);
        FeatureEntitlement::on('platform')->create([
            'plan_id'      => $this->planId,
            'feature_key'  => 'saved_searches_limit',
            'feature_type' => 'integer',
            'int_value'    => 25,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->subscriptionIds !== []) {
            $billing = DB::connection('billing');
            $billing->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete();
            try { $billing->disconnect(); } catch (\Throwable) {}
        }

        $platform = DB::connection('platform');
        if ($this->calloutIds !== []) {
            $platform->table('pricing_callouts')->whereIn('id', $this->calloutIds)->delete();
        }
        $platform->table('plan_versions')->where('plan_id', $this->planId)->delete();
        $platform->table('feature_entitlements')->where('plan_id', $this->planId)->delete();
        $platform->table('membership_plans')->where('id', $this->planId)->delete();
        try { $platform->disconnect(); } catch (\Throwable) {}

        $this->service()->flushPricingCache();

        parent::tearDown();
    }

    private function service(): PlanService
    {
        return app(PlanService::class);
    }

    public function test_publish_snapshots_pricing_and_entitlements(): void
    {
        $plan    = MembershipPlan::on('platform')->find($this->planId);
        $version = $this->service()->publishNewVersion($plan, (string) Str::uuid(), 'price bump');

        $this->assertSame(1, $version->version_number, 'first published version is v1');
        $this->assertSame(1500, $version->monthly_price_cents);
        $this->assertSame(15000, $version->annual_price_cents);
        $this->assertSame('price bump', $version->change_reason);

        $snapshot = $version->entitlements_snapshot;
        $this->assertSame(
            ['type' => 'boolean', 'value' => true],
            $snapshot['trail_camera_integration'],
        );
        $this->assertSame(
            ['type' => 'integer', 'value' => 25],
            $snapshot['saved_searches_limit'],
        );
    }

    public function test_publish_increments_version_number(): void
    {
        $plan = MembershipPlan::on('platform')->find($this->planId);

        $v1 = $this->service()->publishNewVersion($plan, (string) Str::uuid());
        $v2 = $this->service()->publishNewVersion($plan, (string) Str::uuid());

        $this->assertSame(1, $v1->version_number);
        $this->assertSame(2, $v2->version_number);
        $this->assertSame(
            2,
            PlanVersion::on('platform')->where('plan_id', $this->planId)->count(),
        );
    }

    public function test_public_pricing_lists_public_active_plans_with_pricing_perks(): void
    {
        MembershipPlan::on('platform')->where('id', $this->planId)->update([
            'is_public' => true,
            'is_active' => true,
            'tagline'   => 'For the weekend hunter',
        ]);

        // Hide the setUp entitlements, then add one shown perk: only
        // show_on_pricing entitlements appear as card perks.
        FeatureEntitlement::on('platform')
            ->where('plan_id', $this->planId)
            ->update(['show_on_pricing' => false]);

        FeatureEntitlement::on('platform')->create([
            'plan_id'         => $this->planId,
            'feature_key'     => 'priority_support',
            'feature_type'    => 'boolean',
            'bool_value'      => true,
            'display_label'   => 'Priority Support',
            'show_on_pricing' => true,
            'display_order'   => 1,
        ]);

        $this->service()->flushPricingCache();
        $groups = $this->service()->publicPricing();

        $mine = collect($groups['hunter'] ?? [])->firstWhere('plan_key', $this->planKey);

        $this->assertNotNull($mine, 'public plan appears under its account type');
        $this->assertSame('For the weekend hunter', $mine['tagline']);
        $this->assertSame(1500, $mine['monthly_price_cents']);

        $perkLabels = array_column($mine['perks'], 'label');
        $this->assertSame(['Priority Support'], $perkLabels, 'only show_on_pricing entitlements appear, hidden ones excluded');
    }

    public function test_public_pricing_excludes_non_public_plan(): void
    {
        MembershipPlan::on('platform')->where('id', $this->planId)->update([
            'is_public' => false,
            'is_active' => true,
        ]);

        $this->service()->flushPricingCache();
        $groups = $this->service()->publicPricing();

        $mine = collect($groups['hunter'] ?? [])->firstWhere('plan_key', $this->planKey);
        $this->assertNull($mine, 'a non-public plan is not on the pricing page');
    }

    public function test_soft_delete_succeeds_without_subscribers_and_stamps_deprecated_at(): void
    {
        $plan = MembershipPlan::on('platform')->find($this->planId);

        $result = $this->service()->softDeletePlan($plan, (string) Str::uuid());

        $this->assertTrue($result);
        $this->assertNull(
            MembershipPlan::on('platform')->find($this->planId),
            'soft-deleted plan is excluded by the default scope',
        );

        $row = DB::connection('platform')->table('membership_plans')->where('id', $this->planId)->first();
        $this->assertNotNull($row->deleted_at, 'deleted_at is stamped');
        $this->assertNotNull($row->deprecated_at, 'deprecated_at is stamped');
    }

    public function test_soft_delete_is_refused_while_a_subscription_is_active(): void
    {
        $plan    = MembershipPlan::on('platform')->find($this->planId);
        $version = $this->service()->publishNewVersion($plan, (string) Str::uuid());

        $subscription = Subscription::on('billing')->create([
            'user_id'              => (string) Str::uuid(),
            'plan_version_id'      => $version->id,
            'status'               => 'active',
            'current_period_start' => now()->toDateString(),
            'current_period_end'   => now()->addMonth()->toDateString(),
        ]);
        $this->subscriptionIds[] = $subscription->id;

        $result = $this->service()->softDeletePlan($plan, (string) Str::uuid());

        $this->assertFalse($result, 'deletion is refused while a live subscription references a version');
        $this->assertNotNull(
            MembershipPlan::on('platform')->find($this->planId),
            'the plan is left intact',
        );
    }

    public function test_public_callouts_lists_published_callout_grouped_by_tab_with_features(): void
    {
        $callout = \App\Models\Platform\PricingCallout::on('platform')->create([
            'account_type' => 'hunter',
            'eyebrow'      => 'Veteran or First Responder?',
            'body'         => 'Verify your status — your Hunter membership is free, for life.',
            'features'     => [
                ['label' => 'Free for life', 'description' => 'No renewal'],
                ['label' => 'Priority verification', 'description' => null],
            ],
            'buttons'      => [
                ['label' => 'Verify as Veteran',         'url' => '/get-started?type=hunter&service=veteran'],
                ['label' => 'Verify as First Responder', 'url' => '/get-started?type=hunter&service=first_responder'],
            ],
            'is_published' => true,
            'sort_order'   => 5,
        ]);
        $this->calloutIds[] = $callout->id;

        $this->service()->flushPricingCache();
        $callouts = $this->service()->publicCallouts();

        $mine = collect($callouts['hunter'] ?? [])->firstWhere('id', $callout->id);

        $this->assertNotNull($mine, 'a published callout appears under its tab');
        $this->assertSame(
            [
                ['label' => 'Verify as Veteran',         'url' => '/get-started?type=hunter&service=veteran'],
                ['label' => 'Verify as First Responder', 'url' => '/get-started?type=hunter&service=first_responder'],
            ],
            $mine['buttons'],
            'buttons are shaped to {label, url}',
        );
        $this->assertSame(
            [
                ['label' => 'Free for life', 'description' => 'No renewal'],
                ['label' => 'Priority verification', 'description' => null],
            ],
            $mine['features'],
            'features are shaped to {label, description}',
        );
    }

    public function test_public_callouts_excludes_unpublished_callout(): void
    {
        $callout = \App\Models\Platform\PricingCallout::on('platform')->create([
            'account_type' => 'hunter',
            'eyebrow'      => 'Draft callout',
            'body'         => 'Not live yet.',
            'is_published' => false,
        ]);
        $this->calloutIds[] = $callout->id;

        $this->service()->flushPricingCache();
        $callouts = $this->service()->publicCallouts();

        $mine = collect($callouts['hunter'] ?? [])->firstWhere('id', $callout->id);
        $this->assertNull($mine, 'an unpublished callout is not on the pricing page');
    }

    public function test_pricing_index_route_is_publicly_accessible(): void
    {
        // The slash in the Inertia component name is JSON-escaped in the page
        // payload (Public\/Pricing), so match the escaped form.
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Public\\/Pricing', false);
    }
}
