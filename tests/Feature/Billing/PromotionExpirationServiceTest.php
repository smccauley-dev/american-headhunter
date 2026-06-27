<?php

namespace Tests\Feature\Billing;

use App\Jobs\Billing\ExpirePromotionClaims;
use App\Mail\PromotionExpiringMail;
use App\Models\Billing\PromotionClaim;
use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Services\Billing\PromotionExpirationService;
use App\Services\Billing\StripeService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Terminal handling of expired promotion claims, branching on the promo's
 * on_expiration mode (downgrade / auto-charge / pause), plus the daily
 * ExpirePromotionClaims job (reminder windows + expiry processing).
 *
 * Runs against the real billing/platform/identity connections (services declare
 * them), so no DatabaseTransactions — all rows are force-deleted in tearDown.
 * Tests run as the schema owner, which bypasses RLS.
 */
class PromotionExpirationServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $userIds = [];
    /** @var array<int,string> */ private array $periodIds = [];
    /** @var array<int,string> */ private array $planIds = [];

    protected function tearDown(): void
    {
        $billing  = DB::connection('billing');
        $platform = DB::connection('platform');
        $identity = DB::connection('identity');

        if ($this->userIds) {
            $billing->table('subscriptions')->whereIn('user_id', $this->userIds)->delete();
            $billing->table('promotion_claims')->whereIn('user_id', $this->userIds)->delete();
        }
        // promotional_periods.grants_plan_id references membership_plans — drop the
        // periods before the plans they point at.
        if ($this->periodIds) {
            $platform->table('promotional_periods')->whereIn('id', $this->periodIds)->delete();
        }
        if ($this->planIds) {
            $platform->table('plan_versions')->whereIn('plan_id', $this->planIds)->delete();
            $platform->table('membership_plans')->whereIn('id', $this->planIds)->delete();
        }
        if ($this->userIds) {
            $identity->table('user_profiles')->whereIn('user_id', $this->userIds)->delete();
            $identity->table('users')->whereIn('id', $this->userIds)->delete();
        }

        $entitlements = app(EntitlementService::class);
        foreach ($this->userIds as $uid) {
            $entitlements->invalidateForUser($uid);
        }

        Mockery::close();
        parent::tearDown();
    }

    private function service(): PromotionExpirationService
    {
        return app(PromotionExpirationService::class);
    }

    private function makeUser(string $status = 'active'): User
    {
        $id = (string) Str::uuid();
        DB::connection('identity')->table('users')->insert([
            'id'            => $id,
            'email'         => "promoexp-{$id}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => $status,
            'account_type'  => 'hunter',
        ]);
        // Fully populate the account — a leaked fixture (interrupted run) should
        // never show up nameless on /admin/platform-users.
        DB::connection('identity')->table('user_profiles')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $id,
            'first_name' => 'PromoExp',
            'last_name'  => 'Test User',
        ]);
        $this->userIds[] = $id;

        return User::on('identity')->find($id);
    }

    private function makePeriod(string $onExpiration, ?string $grantsPlanId = null): string
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('promotional_periods')->insert([
            'id'             => $id,
            'promo_key'      => 'promoexp_' . Str::random(8),
            'display_name'   => 'Promo Expiration Test',
            'promotion_type' => 'tier_grant',
            'status'         => 'active',
            'grants_plan_id' => $grantsPlanId,
            'on_expiration'  => $onExpiration,
            'duration_days'  => 30,
        ]);
        $this->periodIds[] = $id;

        return $id;
    }

    /** A grantable plan + current version. $priceId null = no Stripe price synced. */
    private function makePlan(?string $priceId): array
    {
        $planId = (string) Str::uuid();
        DB::connection('platform')->table('membership_plans')->insert([
            'id'                      => $planId,
            'plan_key'                => 'promoexp_plan_' . Str::random(6),
            'account_type'            => 'hunter',
            'display_name'            => 'Promo Exp Plan',
            'monthly_price_cents'     => 1999,
            'annual_price_cents'      => 19999,
            'stripe_monthly_price_id' => $priceId,
            // These rows are written to the real dev DB (no transaction) and rely on
            // tearDown to remove them. Keep them off the public pricing page so a
            // leaked fixture (interrupted run) can never surface as a phantom plan.
            'is_public'               => false,
            'is_active'               => false,
        ]);
        $this->planIds[] = $planId;

        $versionId = (string) Str::uuid();
        DB::connection('platform')->table('plan_versions')->insert([
            'id'                    => $versionId,
            'plan_id'               => $planId,
            'version_number'        => 1,
            'plan_key'              => 'promoexp_plan',
            'display_name'          => 'Promo Exp Plan',
            'monthly_price_cents'   => 1999,
            'annual_price_cents'    => 19999,
            'entitlements_snapshot' => '{}',
        ]);

        return ['plan_id' => $planId, 'version_id' => $versionId];
    }

    private function makeClaim(User $user, string $periodId, array $overrides = []): PromotionClaim
    {
        return PromotionClaim::create(array_merge([
            'user_id'             => $user->id,
            'promotion_period_id' => $periodId,
            'status'              => 'active',
            'duration_days'       => 30,
            'activated_at'        => now()->subDays(30),
            'expires_at'          => now()->subMinute(),
            'trigger_event'       => 'manual_admin',
        ], $overrides));
    }

    // ── downgrade_free ─────────────────────────────────────────────────────────────

    public function test_downgrade_free_marks_claim_expired_and_unlinks_subscription(): void
    {
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('downgrade_free');
        $claim    = $this->makeClaim($user, $periodId);

        $sub = Subscription::create([
            'user_id'                   => $user->id,
            'plan_version_id'           => (string) Str::uuid(),
            'active_promotion_claim_id' => $claim->id,
            'status'                    => 'active',
            'billing_interval'          => 'monthly',
            'current_period_start'      => now()->toDateString(),
            'current_period_end'        => now()->addMonth()->toDateString(),
        ]);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('downgraded_free', $outcome);
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertNull($sub->fresh()->active_promotion_claim_id);
    }

    // ── pause_account ─────────────────────────────────────────────────────────────

    public function test_pause_account_pauses_an_active_user(): void
    {
        $user     = $this->makeUser('active');
        $periodId = $this->makePeriod('pause_account');
        $claim    = $this->makeClaim($user, $periodId);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('paused', $outcome);
        $this->assertSame('paused', $user->fresh()->status);
        $this->assertSame('expired', $claim->fresh()->status);
    }

    public function test_pause_account_never_overrides_a_moderation_state(): void
    {
        $user     = $this->makeUser('suspended');
        $periodId = $this->makePeriod('pause_account');
        $claim    = $this->makeClaim($user, $periodId);

        $this->service()->expire($claim->fresh());

        $this->assertSame('suspended', $user->fresh()->status, 'a suspended account is not flipped to paused');
    }

    // ── auto_charge ─────────────────────────────────────────────────────────────────

    public function test_auto_charge_converts_to_a_paid_subscription(): void
    {
        $plan     = $this->makePlan('price_test_123');
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('auto_charge', $plan['plan_id']);
        $claim    = $this->makeClaim($user, $periodId, [
            'granted_plan_id'         => $plan['plan_id'],
            'granted_plan_version_id' => $plan['version_id'],
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('getOrCreateCustomer')->once()->andReturn('cus_test');
        $stripe->shouldReceive('createSubscription')->once()
            ->andReturn(\Stripe\Subscription::constructFrom(['id' => 'sub_test']));
        $stripe->shouldReceive('subscriptionPeriod')->once()->andReturn([
            'interval'             => 'monthly',
            'current_period_start' => now()->toDateString(),
            'current_period_end'   => now()->addMonth()->toDateString(),
        ]);
        $this->app->instance(StripeService::class, $stripe);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('converted', $outcome);
        $this->assertSame('converted', $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->converted_at);
        $sub = Subscription::where('user_id', $user->id)->where('status', 'active')->first();
        $this->assertNotNull($sub);
        $this->assertSame('sub_test', $sub->stripe_subscription_id);
    }

    public function test_auto_charge_falls_back_to_downgrade_when_no_price_is_synced(): void
    {
        $plan     = $this->makePlan(null); // no stripe price
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('auto_charge', $plan['plan_id']);
        $claim    = $this->makeClaim($user, $periodId, [
            'granted_plan_id'         => $plan['plan_id'],
            'granted_plan_version_id' => $plan['version_id'],
        ]);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('downgraded_free', $outcome);
        $this->assertSame('expired', $claim->fresh()->status);
    }

    public function test_auto_charge_failure_downgrades_rather_than_leaving_mid_conversion(): void
    {
        $plan     = $this->makePlan('price_test_123');
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('auto_charge', $plan['plan_id']);
        $claim    = $this->makeClaim($user, $periodId, [
            'granted_plan_id'         => $plan['plan_id'],
            'granted_plan_version_id' => $plan['version_id'],
        ]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('getOrCreateCustomer')->andReturn('cus_test');
        $stripe->shouldReceive('createSubscription')->andThrow(new \RuntimeException('card_declined'));
        $this->app->instance(StripeService::class, $stripe);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('auto_charge_failed', $outcome);
        $this->assertSame('expired', $claim->fresh()->status);
        $this->assertNull(Subscription::where('user_id', $user->id)->where('status', 'active')->first());
    }

    public function test_auto_charge_on_an_already_paying_member_just_ends_the_discount(): void
    {
        $plan     = $this->makePlan('price_test_123');
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('auto_charge', $plan['plan_id']);
        $claim    = $this->makeClaim($user, $periodId, [
            'granted_plan_id'         => $plan['plan_id'],
            'granted_plan_version_id' => $plan['version_id'],
        ]);

        // Member already pays — the promo was a discount riding on this sub.
        Subscription::create([
            'user_id'                   => $user->id,
            'plan_version_id'           => $plan['version_id'],
            'active_promotion_claim_id' => $claim->id,
            'status'                    => 'active',
            'billing_interval'          => 'monthly',
            'current_period_start'      => now()->toDateString(),
            'current_period_end'        => now()->addMonth()->toDateString(),
        ]);

        $outcome = $this->service()->expire($claim->fresh());

        $this->assertSame('downgraded_free', $outcome);
        $this->assertSame('expired', $claim->fresh()->status);
    }

    // ── reactivate ─────────────────────────────────────────────────────────────────

    public function test_reactivate_lifts_a_paused_account(): void
    {
        $user = $this->makeUser('paused');

        $this->assertTrue($this->service()->reactivate($user));
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_reactivate_is_a_noop_for_a_non_paused_account(): void
    {
        $user = $this->makeUser('active');

        $this->assertFalse($this->service()->reactivate($user));
        $this->assertSame('active', $user->fresh()->status);
    }

    // ── ExpirePromotionClaims job ───────────────────────────────────────────────────

    public function test_job_processes_expired_claims(): void
    {
        Mail::fake();
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('downgrade_free');
        $claim    = $this->makeClaim($user, $periodId, ['expires_at' => now()->subHour()]);

        (new ExpirePromotionClaims)->handle(app(PromotionExpirationService::class), app(\App\Services\Identity\UserService::class));

        $this->assertSame('expired', $claim->fresh()->status);
    }

    public function test_job_sends_one_reminder_per_window_and_is_idempotent(): void
    {
        Mail::fake();
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('downgrade_free');
        // 5 days out → the 7-day window.
        $claim    = $this->makeClaim($user, $periodId, ['expires_at' => now()->addDays(5)]);

        (new ExpirePromotionClaims)->handle(app(PromotionExpirationService::class), app(\App\Services\Identity\UserService::class));

        Mail::assertSent(PromotionExpiringMail::class, 1);
        $this->assertNotNull($claim->fresh()->reminder_7d_sent_at);
        $this->assertNotNull($claim->fresh()->reminder_30d_sent_at, '7-day send backfills the 30-day window');
        $this->assertNull($claim->fresh()->reminder_1d_sent_at);

        // A second run the same day must not resend the 7-day reminder.
        (new ExpirePromotionClaims)->handle(app(PromotionExpirationService::class), app(\App\Services\Identity\UserService::class));
        Mail::assertSent(PromotionExpiringMail::class, 1);
    }

    public function test_job_does_not_remind_on_claims_beyond_30_days(): void
    {
        Mail::fake();
        $user     = $this->makeUser();
        $periodId = $this->makePeriod('downgrade_free');
        $this->makeClaim($user, $periodId, ['expires_at' => now()->addDays(45)]);

        (new ExpirePromotionClaims)->handle(app(PromotionExpirationService::class), app(\App\Services\Identity\UserService::class));

        Mail::assertNothingSent();
    }
}
