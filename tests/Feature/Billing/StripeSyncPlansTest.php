<?php

namespace Tests\Feature\Billing;

use App\Models\Platform\MembershipPlan;
use App\Services\Billing\StripeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * Phase 5.3 — `stripe:sync-plans` pushes plans to Stripe and stores the returned
 * Product/Price ids back on membership_plans (DB 12). StripeService is mocked so
 * no Stripe API call is made; the command's persistence + idempotency is what's
 * under test. Real plan rows are mutated, so the stripe_* columns are snapshotted
 * and restored in tearDown.
 */
class StripeSyncPlansTest extends TestCase
{
    /** @var array<string,array<string,?string>> plan id => original stripe_* values */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $plans = DB::connection('platform')->table('membership_plans')
            ->where('is_active', true)
            ->get(['id', 'stripe_product_id', 'stripe_monthly_price_id', 'stripe_annual_price_id']);

        foreach ($plans as $p) {
            $this->snapshot[$p->id] = [
                'stripe_product_id'       => $p->stripe_product_id,
                'stripe_monthly_price_id' => $p->stripe_monthly_price_id,
                'stripe_annual_price_id'  => $p->stripe_annual_price_id,
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $id => $values) {
            DB::connection('platform')->table('membership_plans')->where('id', $id)->update($values);
        }

        try { DB::connection('platform')->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    /** A mock that hands back deterministic ids and is bound into the container. */
    private function bindStripeMock(): \Mockery\MockInterface
    {
        $stripe = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $stripe);

        return $stripe;
    }

    public function test_sync_writes_product_and_price_ids_to_plans(): void
    {
        $stripe = $this->bindStripeMock();

        $stripe->shouldReceive('upsertProduct')
            ->andReturnUsing(fn (MembershipPlan $plan) => 'prod_' . $plan->plan_key);
        $stripe->shouldReceive('createPrice')
            ->andReturnUsing(fn (string $product, int $cents, string $interval) => "price_{$interval}_" . substr(md5($product . $cents), 0, 8));

        Artisan::call('stripe:sync-plans');

        // Every active plan now carries a product id.
        $unsynced = DB::connection('platform')->table('membership_plans')
            ->where('is_active', true)->whereNull('stripe_product_id')->count();
        $this->assertSame(0, $unsynced, 'all active plans get a Stripe product id');

        // A paid plan with monthly enabled carries a monthly price id.
        $paid = MembershipPlan::on('platform')
            ->where('is_active', true)
            ->where('monthly_enabled', true)
            ->where('monthly_price_cents', '>', 0)
            ->first();

        if ($paid) {
            $fresh = MembershipPlan::on('platform')->find($paid->id);
            $this->assertStringStartsWith('prod_', $fresh->stripe_product_id);
            $this->assertStringStartsWith('price_monthly_', $fresh->stripe_monthly_price_id);
        }
    }

    public function test_rerun_keeps_existing_price_ids(): void
    {
        // First run mints ids.
        $first = $this->bindStripeMock();
        $first->shouldReceive('upsertProduct')->andReturnUsing(fn (MembershipPlan $p) => 'prod_' . $p->plan_key);
        $first->shouldReceive('createPrice')->andReturnUsing(fn (string $prod, int $c, string $i) => "price_{$i}_" . substr(md5($prod . $c), 0, 8));
        Artisan::call('stripe:sync-plans');

        // Second run: products are re-upserted, but no new Prices may be created
        // because every enabled interval already has a stored id.
        $second = $this->bindStripeMock();
        $second->shouldReceive('upsertProduct')->andReturnUsing(fn (MembershipPlan $p) => $p->stripe_product_id ?? 'prod_' . $p->plan_key);
        $second->shouldNotReceive('createPrice');

        Artisan::call('stripe:sync-plans');

        $this->assertTrue(true, 'idempotent re-run does not mint new prices');
    }

    public function test_refresh_mints_new_price_ids(): void
    {
        $first = $this->bindStripeMock();
        $first->shouldReceive('upsertProduct')->andReturnUsing(fn (MembershipPlan $p) => 'prod_' . $p->plan_key);
        $first->shouldReceive('createPrice')->andReturn('price_original');
        Artisan::call('stripe:sync-plans');

        $second = $this->bindStripeMock();
        $second->shouldReceive('upsertProduct')->andReturnUsing(fn (MembershipPlan $p) => $p->stripe_product_id ?? 'prod_' . $p->plan_key);
        // --refresh forces a fresh Price for every enabled, paid interval.
        $second->shouldReceive('createPrice')->atLeast()->once()->andReturn('price_refreshed');

        Artisan::call('stripe:sync-plans', ['--refresh' => true]);

        $refreshed = MembershipPlan::on('platform')
            ->where('is_active', true)
            ->where('monthly_enabled', true)
            ->where('monthly_price_cents', '>', 0)
            ->first();

        if ($refreshed) {
            $this->assertSame('price_refreshed', MembershipPlan::on('platform')->find($refreshed->id)->stripe_monthly_price_id);
        }
    }
}
