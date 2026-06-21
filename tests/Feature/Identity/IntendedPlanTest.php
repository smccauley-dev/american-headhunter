<?php

namespace Tests\Feature\Identity;

use App\Models\Identity\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\MfaService;
use App\Services\Billing\SubscriptionService;
use App\Services\Identity\UserService;
use App\Services\Platform\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Option A — the membership plan a user picks before signing up follows them.
 *
 * create() persists `intended_plan_key` only when the chosen key resolves to a
 * real public plan for that account type; takeIntendedPlanRedirect() consumes it
 * once at first login, routing a paid plan (with no active subscription) to the
 * pricing page and leaving free/unknown plans alone.
 *
 * PlanService and SubscriptionService are mocked so the tests do not depend on
 * seeded DB-12 plans or DB-4 subscription rows. AuditService is mocked so create()
 * writes no permanent audit row. The identity connection is rolled back in tearDown.
 */
class IntendedPlanTest extends TestCase
{
    private UserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('identity')->beginTransaction();

        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('logAccountCreated')->andReturnNull();

        $this->service = new UserService($audit, app(MfaService::class));
    }

    protected function tearDown(): void
    {
        try {
            DB::connection('identity')->rollBack();
        } catch (\Throwable) {}

        Mockery::close();
        parent::tearDown();
    }

    private function fakePlanService(array $plansByKey): void
    {
        $plans = Mockery::mock(PlanService::class);
        $plans->shouldReceive('findPublicPlan')
            ->andReturnUsing(fn (string $key) => $plansByKey[$key] ?? null);
        $this->app->instance(PlanService::class, $plans);
    }

    private function makeUser(array $overrides = []): User
    {
        return $this->service->create(array_merge([
            'email'        => 'plan-' . Str::uuid() . '@test.invalid',
            'password'     => 'Sup3rSecret!!',
            'account_type' => 'hunter',
            'first_name'   => 'Jane',
            'last_name'    => 'Hunter',
            'tos_version'  => '2026-01-01',
        ], $overrides));
    }

    public function test_create_persists_matching_paid_plan(): void
    {
        $this->fakePlanService([
            'hunter_sportsman' => [
                'plan_key' => 'hunter_sportsman', 'display_name' => 'Sportsman',
                'account_type' => 'hunter', 'is_paid' => true,
            ],
        ]);

        $user = $this->makeUser(['plan' => 'hunter_sportsman']);

        $this->assertSame('hunter_sportsman', DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('intended_plan_key'));
    }

    public function test_create_drops_plan_for_mismatched_account_type(): void
    {
        $this->fakePlanService([
            'landowner_pro' => [
                'plan_key' => 'landowner_pro', 'display_name' => 'Pro',
                'account_type' => 'landowner', 'is_paid' => true,
            ],
        ]);

        // Hunter signup carrying a landowner plan key — not buyable, so dropped.
        $user = $this->makeUser(['account_type' => 'hunter', 'plan' => 'landowner_pro']);

        $this->assertNull(DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('intended_plan_key'));
    }

    public function test_create_drops_unknown_plan(): void
    {
        $this->fakePlanService([]); // findPublicPlan always returns null

        $user = $this->makeUser(['plan' => 'does_not_exist']);

        $this->assertNull(DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('intended_plan_key'));
    }

    public function test_redirect_routes_paid_plan_to_pricing_and_clears_key(): void
    {
        $this->fakePlanService([
            'hunter_sportsman' => [
                'plan_key' => 'hunter_sportsman', 'display_name' => 'Sportsman',
                'account_type' => 'hunter', 'is_paid' => true,
            ],
        ]);

        $subs = Mockery::mock(SubscriptionService::class);
        $subs->shouldReceive('activeFor')->andReturnNull(); // no active subscription
        $this->app->instance(SubscriptionService::class, $subs);

        $user = $this->makeUser(['plan' => 'hunter_sportsman']);

        $this->assertSame(
            '/pricing?plan=hunter_sportsman',
            $this->service->takeIntendedPlanRedirect($user),
        );

        // Consumed once — the key is cleared on the row.
        $this->assertNull(DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('intended_plan_key'));
    }

    public function test_redirect_skips_paid_plan_with_active_subscription(): void
    {
        $this->fakePlanService([
            'hunter_sportsman' => [
                'plan_key' => 'hunter_sportsman', 'display_name' => 'Sportsman',
                'account_type' => 'hunter', 'is_paid' => true,
            ],
        ]);

        $subs = Mockery::mock(SubscriptionService::class);
        $subs->shouldReceive('activeFor')
            ->andReturn(Mockery::mock(\App\Models\Billing\Subscription::class)); // active subscription
        $this->app->instance(SubscriptionService::class, $subs);

        $user = $this->makeUser(['plan' => 'hunter_sportsman']);

        $this->assertNull($this->service->takeIntendedPlanRedirect($user));
    }

    public function test_redirect_returns_null_for_free_plan(): void
    {
        $this->fakePlanService([
            'hunter_free' => [
                'plan_key' => 'hunter_free', 'display_name' => 'Free',
                'account_type' => 'hunter', 'is_paid' => false,
            ],
        ]);

        $user = $this->makeUser(['plan' => 'hunter_free']);

        $this->assertNull($this->service->takeIntendedPlanRedirect($user));

        // Still cleared even though no redirect is issued.
        $this->assertNull(DB::connection('identity')
            ->table('users')->where('id', $user->id)->value('intended_plan_key'));
    }

    public function test_redirect_returns_null_when_no_plan_stored(): void
    {
        $user = $this->makeUser();

        $this->assertNull($this->service->takeIntendedPlanRedirect($user));
    }
}
