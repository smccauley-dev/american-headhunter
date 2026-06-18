<?php

namespace Tests\Feature\Billing;

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

    protected function setUp(): void
    {
        parent::setUp();

        $plan = MembershipPlan::on('platform')->create([
            'plan_key'            => 'test_plan_' . Str::random(8),
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
        $platform = DB::connection('platform');
        $platform->table('plan_versions')->where('plan_id', $this->planId)->delete();
        $platform->table('feature_entitlements')->where('plan_id', $this->planId)->delete();
        $platform->table('membership_plans')->where('id', $this->planId)->delete();
        try { $platform->disconnect(); } catch (\Throwable) {}

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
}
