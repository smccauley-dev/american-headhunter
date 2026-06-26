<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Payout;
use App\Models\Identity\User;
use App\Services\Billing\PayoutService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.5 PayoutService — per-tier platform-fee resolution and Stripe Connect
 * disbursement.
 *
 * Runs against the real `billing` and `platform` connections (the models declare
 * them). Stripe itself is mocked — no live keys needed — so this proves the fee
 * math, the disburse record, and the no-account guard without touching Stripe.
 * Rows are force-deleted in tearDown.
 */
class PayoutServiceTest extends TestCase
{
    /** @var array<int,string> */ private array $userIds = [];
    /** @var array<int,string> */ private array $versionIds = [];
    /** @var array<int,string> */ private array $subscriptionIds = [];

    private string $homesteadPlanId;
    private float  $homesteadFeePct;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = DB::connection('platform')->table('membership_plans')
            ->where('plan_key', 'landowner_homestead')
            ->first(['id', 'platform_fee_pct']);

        if (! $plan) {
            $this->markTestSkipped('landowner_homestead plan not seeded.');
        }

        $this->homesteadPlanId = $plan->id;
        $this->homesteadFeePct = (float) $plan->platform_fee_pct;
    }

    protected function tearDown(): void
    {
        $billing  = DB::connection('billing');
        $platform = DB::connection('platform');

        if ($this->userIds) {
            $billing->table('payouts')->whereIn('payee_user_id', $this->userIds)->delete();
            $billing->table('stripe_accounts')->whereIn('user_id', $this->userIds)->delete();
        }
        if ($this->subscriptionIds) { $billing->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete(); }
        if ($this->versionIds)      { $platform->table('plan_versions')->whereIn('id', $this->versionIds)->delete(); }

        try { $billing->disconnect(); } catch (\Throwable) {}
        try { $platform->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function service(): PayoutService
    {
        return app(PayoutService::class);
    }

    private function makeLandowner(): User
    {
        $u = new User();
        $u->id           = (string) Str::uuid();
        $u->account_type = 'landowner';
        $u->email        = "landowner-{$u->id}@test.invalid";
        $this->userIds[] = $u->id;

        return $u;
    }

    /** A superseded landowner plan_version carrying its own platform fee. */
    private function makeVersion(float $feePct): string
    {
        $id = (string) Str::uuid();
        DB::connection('platform')->table('plan_versions')->insert([
            'id'                  => $id,
            'plan_id'             => $this->homesteadPlanId,
            'version_number'      => random_int(100000, 999999),
            'plan_key'            => 'landowner_test',
            'display_name'        => 'Test Landowner Version',
            'monthly_price_cents' => 7900,
            'annual_price_cents'  => 79000,
            'platform_fee_pct'    => $feePct,
            'superseded_at'       => now(),
            'effective_from'      => now(),
            'created_at'          => now(),
        ]);
        $this->versionIds[] = $id;

        return $id;
    }

    private function seedConnectAccount(string $userId, bool $payoutsEnabled): void
    {
        DB::connection('billing')->table('stripe_accounts')->insert([
            'id'                => (string) Str::uuid(),
            'user_id'           => $userId,
            'stripe_account_id' => 'acct_test_' . Str::random(10),
            'charges_enabled'   => true,
            'payouts_enabled'   => $payoutsEnabled,
            'details_submitted' => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_free_tier_landowner_uses_plan_platform_fee(): void
    {
        $landowner = $this->makeLandowner();

        $this->assertSame($this->homesteadFeePct, $this->service()->platformFeePct($landowner));
    }

    public function test_subscription_version_fee_overrides_free_tier(): void
    {
        $landowner = $this->makeLandowner();
        $versionId = $this->makeVersion(2.00);

        $sub = app(SubscriptionService::class)->start($landowner->id, $versionId);
        $this->subscriptionIds[] = $sub->id;

        $this->assertSame(2.0, $this->service()->platformFeePct($landowner));
    }

    public function test_quote_withholds_fee_and_nets_the_remainder(): void
    {
        $landowner = $this->makeLandowner();
        $versionId = $this->makeVersion(3.00);

        $sub = app(SubscriptionService::class)->start($landowner->id, $versionId);
        $this->subscriptionIds[] = $sub->id;

        $quote = $this->service()->quote($landowner, 100_000);

        $this->assertSame(100_000, $quote['gross_cents']);
        $this->assertSame(3.0, $quote['fee_pct']);
        $this->assertSame(3_000, $quote['fee_cents']);
        $this->assertSame(97_000, $quote['net_cents']);
    }

    public function test_disburse_transfers_net_and_records_payout(): void
    {
        $landowner = $this->makeLandowner();
        $versionId = $this->makeVersion(2.00);
        $sub = app(SubscriptionService::class)->start($landowner->id, $versionId);
        $this->subscriptionIds[] = $sub->id;
        $this->seedConnectAccount($landowner->id, payoutsEnabled: true);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('createTransfer')
                ->once()
                ->with(98_000, \Mockery::type('string'), \Mockery::type('array'))
                ->andReturn(\Stripe\Transfer::constructFrom(['id' => 'tr_test_123']));
        });

        $payout = $this->service()->disburse($landowner, 100_000, ['lease_id' => 'lease-1']);

        $this->assertInstanceOf(Payout::class, $payout);
        $this->assertSame(98_000, $payout->amount_cents);
        $this->assertSame('in_transit', $payout->status);
        $this->assertSame($landowner->id, $payout->payee_user_id);
        $this->assertSame('tr_test_123', $payout->getAttribute('stripe_transfer_id'));
    }

    public function test_disburse_refuses_without_payouts_enabled_account(): void
    {
        $landowner = $this->makeLandowner();
        $this->seedConnectAccount($landowner->id, payoutsEnabled: false);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('createTransfer');
        });

        $this->expectException(\RuntimeException::class);
        $this->service()->disburse($landowner, 100_000);
    }

    // ── Connect onboarding (slice 2) ─────────────────────────────────────────────

    public function test_onboarding_state_without_an_account(): void
    {
        $landowner = $this->makeLandowner();

        $state = $this->service()->onboardingState($landowner);

        $this->assertFalse($state['connected']);
        $this->assertFalse($state['onboarded']);
    }

    public function test_onboarding_state_reflects_payouts_enabled(): void
    {
        $landowner = $this->makeLandowner();
        $this->seedConnectAccount($landowner->id, payoutsEnabled: true);

        $state = $this->service()->onboardingState($landowner);

        $this->assertTrue($state['connected']);
        $this->assertTrue($state['payouts_enabled']);
        $this->assertTrue($state['onboarded']);
    }

    public function test_start_onboarding_creates_account_and_returns_link(): void
    {
        $landowner = $this->makeLandowner();

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('createConnectAccount')
                ->once()
                ->with(\Mockery::type(User::class))
                ->andReturn('acct_new_123');
            $mock->shouldReceive('createAccountLink')
                ->once()
                ->with('acct_new_123', \Mockery::type('string'), \Mockery::type('string'))
                ->andReturn('https://connect.stripe.test/onboard');
        });

        $url = $this->service()->startOnboarding($landowner, 'https://app.test/return', 'https://app.test/refresh');

        $this->assertSame('https://connect.stripe.test/onboard', $url);
        $this->assertSame(1, DB::connection('billing')->table('stripe_accounts')
            ->where('user_id', $landowner->id)->where('stripe_account_id', 'acct_new_123')->count());
    }

    public function test_start_onboarding_reuses_an_existing_account(): void
    {
        $landowner = $this->makeLandowner();
        $this->seedConnectAccount($landowner->id, payoutsEnabled: false);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('createConnectAccount');
            $mock->shouldReceive('createAccountLink')
                ->once()
                ->andReturn('https://connect.stripe.test/resume');
        });

        $url = $this->service()->startOnboarding($landowner, 'https://app.test/return', 'https://app.test/refresh');

        $this->assertSame('https://connect.stripe.test/resume', $url);
        // Still exactly one account — no duplicate was minted.
        $this->assertSame(1, DB::connection('billing')->table('stripe_accounts')
            ->where('user_id', $landowner->id)->count());
    }
}
