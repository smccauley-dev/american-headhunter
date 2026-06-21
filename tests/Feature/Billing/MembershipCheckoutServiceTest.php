<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Models\Identity\User;
use App\Models\Platform\PlanVersion;
use App\Services\Billing\MembershipCheckoutService;
use App\Services\Billing\PromoCodeService;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Decision branches of MembershipCheckoutService::start — the logic shared by the
 * member /pricing checkout and the signup flow (a paid plan picked at registration
 * goes straight to Stripe instead of being deferred).
 *
 * Stripe / Subscription / PromoCode collaborators are mocked so no external call
 * is made and the test does not depend on seeded billing rows. Plan lookups use
 * the real seeded DB-12 plans (the service reads them by key).
 */
class MembershipCheckoutServiceTest extends TestCase
{
    private function makeUser(string $accountType = 'hunter'): User
    {
        $user = new User();
        $user->id = (string) Str::uuid();
        $user->account_type = $accountType;

        return $user;
    }

    /**
     * Resolve the service with its collaborators mocked. The callbacks let each
     * test program the Stripe / Subscription / PromoCode behavior it needs.
     */
    private function service(
        ?callable $subs = null,
        ?callable $stripe = null,
        ?callable $promo = null,
    ): MembershipCheckoutService {
        $subsMock = Mockery::mock(SubscriptionService::class);
        $subsMock->shouldReceive('activeFor')->andReturnNull()->byDefault();
        if ($subs) { $subs($subsMock); }
        $this->app->instance(SubscriptionService::class, $subsMock);

        $stripeMock = Mockery::mock(StripeService::class);
        if ($stripe) { $stripe($stripeMock); }
        $this->app->instance(StripeService::class, $stripeMock);

        $promoMock = Mockery::mock(PromoCodeService::class);
        $promoMock->shouldReceive('autoApplyForPlan')->andReturnNull()->byDefault();
        if ($promo) { $promo($promoMock); }
        $this->app->instance(PromoCodeService::class, $promoMock);

        return app(MembershipCheckoutService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_existing_active_subscription_is_rejected(): void
    {
        $service = $this->service(subs: function ($m) {
            $m->shouldReceive('activeFor')->andReturn(Mockery::mock(Subscription::class));
        });

        $result = $service->start($this->makeUser(), 'hunter_pro', 'monthly', null, 'https://ok', 'https://no');

        $this->assertSame('plan_key', $result['field']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_unknown_plan_is_rejected(): void
    {
        $result = $this->service()->start($this->makeUser(), 'does_not_exist', 'monthly', null, 'https://ok', 'https://no');

        $this->assertSame('plan_key', $result['field']);
    }

    public function test_mismatched_account_type_is_rejected(): void
    {
        // Hunter user attempting a landowner plan.
        $result = $this->service()->start($this->makeUser('hunter'), 'landowner_ranch', 'monthly', null, 'https://ok', 'https://no');

        $this->assertSame('plan_key', $result['field']);
    }

    public function test_free_plan_is_rejected(): void
    {
        $result = $this->service()->start($this->makeUser('hunter'), 'hunter_scout', 'monthly', null, 'https://ok', 'https://no');

        $this->assertSame('plan_key', $result['field']);
    }

    public function test_paid_plan_returns_checkout_url(): void
    {
        // Real (unsaved) instances — the service only reads ->id / ->url, and
        // mocking these would intercept the magic property access.
        $version = new PlanVersion();
        $version->id = (string) Str::uuid();

        $session = Session::constructFrom(['url' => 'https://checkout.stripe.test/session-123']);

        $service = $this->service(
            stripe: function ($m) use ($session) {
                $m->shouldReceive('createSubscriptionCheckoutSession')->once()->andReturn($session);
            },
            subs: function ($m) use ($version) {
                $m->shouldReceive('currentVersionForPlan')->with('hunter_pro')->andReturn($version);
            },
        );

        $result = $service->start($this->makeUser('hunter'), 'hunter_pro', 'monthly', null, 'https://ok', 'https://no');

        $this->assertSame('https://checkout.stripe.test/session-123', $result['url']);
    }
}
