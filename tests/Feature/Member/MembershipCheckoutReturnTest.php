<?php

namespace Tests\Feature\Member;

use App\Models\Billing\Subscription;
use App\Services\Billing\StripeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

/**
 * Membership checkout success-return reconcile. Stripe redirects the member back
 * to /member/membership/checkout/return?session_id=... immediately on payment;
 * the route (running as ah_system) retrieves the session and authors the local
 * subscription up front so the membership tab shows the new plan on this render
 * instead of the old (free) plan until the async webhook lands.
 *
 * Stripe is mocked; the subscription row is real on the billing connection (owner
 * role in tests bypasses RLS) and removed in tearDown.
 */
class MembershipCheckoutReturnTest extends TestCase
{
    private string $userId;
    private string $planVersionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId        = (string) Str::uuid();
        $this->planVersionId = (string) Str::uuid();

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "member-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('subscriptions')->where('user_id', $this->userId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();

        parent::tearDown();
    }

    /** A completed subscription Checkout payload for this member. */
    private function sessionPayload(string $userId, string $subId): array
    {
        return [
            'mode'         => 'subscription',
            'subscription' => $subId,
            'customer'     => 'cus_' . Str::random(14),
            'metadata'     => [
                'user_id'         => $userId,
                'plan_version_id' => $this->planVersionId,
            ],
        ];
    }

    private function mockStripe(string $sessionId, array $payload): void
    {
        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('retrieveCheckoutSession')
            ->with($sessionId)
            ->andReturn(Session::constructFrom($payload));
        // Period read is best-effort inside the reconcile; let it fall back to the
        // monthly estimate so the test doesn't depend on a live Stripe period.
        $stripe->shouldReceive('subscriptionPeriod')
            ->andThrow(new \RuntimeException('period unavailable'));

        $this->app->instance(StripeService::class, $stripe);
    }

    public function test_return_reconciles_the_subscription_and_redirects(): void
    {
        $subId = 'sub_' . Str::random(14);
        $this->mockStripe('cs_match', $this->sessionPayload($this->userId, $subId));

        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/membership/checkout/return?session_id=cs_match')
            ->assertRedirect(route('member.membership') . '?checkout=success');

        $sub = Subscription::where('user_id', $this->userId)->first();
        $this->assertNotNull($sub, 'the subscription should be authored on return');
        $this->assertSame('active', $sub->status);
        $this->assertSame($subId, $sub->stripe_subscription_id);
        $this->assertSame($this->planVersionId, $sub->plan_version_id);
    }

    public function test_return_ignores_a_session_for_a_different_member(): void
    {
        // The session belongs to some other member; reaching it through this
        // member's session must not author a subscription here.
        $this->mockStripe('cs_other', $this->sessionPayload((string) Str::uuid(), 'sub_other'));

        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/membership/checkout/return?session_id=cs_other')
            ->assertRedirect(route('member.membership') . '?checkout=success');

        $this->assertSame(0, Subscription::where('user_id', $this->userId)->count());
    }

    public function test_return_without_a_session_id_just_redirects(): void
    {
        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/membership/checkout/return')
            ->assertRedirect(route('member.membership') . '?checkout=success');

        $this->assertSame(0, Subscription::where('user_id', $this->userId)->count());
    }
}
