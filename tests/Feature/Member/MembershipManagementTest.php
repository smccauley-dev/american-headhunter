<?php

namespace Tests\Feature\Member;

use App\Models\Billing\Subscription;
use App\Services\Billing\StripeService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.3 (Phase 2) — self-service cancel / resume of a paid membership.
 *
 * The controller talks to Stripe through StripeService, which is mocked here so
 * the suite never makes a network call; the assertions cover the local mirror
 * (subscriptions.cancelled_at) that the member sees immediately. Stripe's own
 * webhook reconciliation is covered by ProcessStripeWebhookTest.
 *
 * Created rows live on the real `billing`/`identity` connections and are removed
 * in tearDown; the entitlement cache is invalidated for the test user.
 */
class MembershipManagementTest extends TestCase
{
    private string $userId;
    private string $subscriptionId;
    private string $stripeSubId;
    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId      = (string) Str::uuid();
        $this->stripeSubId = 'sub_' . Str::random(14);
        $this->versionId   = app(SubscriptionService::class)
            ->currentVersionForPlan('hunter_scout')->id;

        DB::connection('identity')->table('users')->insert([
            'id'            => $this->userId,
            'email'         => "member-{$this->userId}@test.invalid",
            'password_hash' => 'test-hash',
            'status'        => 'active',
            'account_type'  => 'hunter',
        ]);

        $sub = app(SubscriptionService::class)->start($this->userId, $this->versionId, [
            'stripe_subscription_id' => $this->stripeSubId,
            'stripe_customer_id'     => 'cus_manage',
            'status'                 => 'active',
        ]);
        $this->subscriptionId = $sub->id;
    }

    protected function tearDown(): void
    {
        DB::connection('billing')->table('subscriptions')->where('id', $this->subscriptionId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();
        app(EntitlementService::class)->invalidateForUser($this->userId);

        parent::tearDown();
    }

    private function stripeSubscription(array $attributes): \Stripe\Subscription
    {
        return \Stripe\Subscription::constructFrom($attributes);
    }

    public function test_cancel_schedules_cancellation_at_period_end(): void
    {
        $cancelAt = now()->addDays(20)->timestamp;

        $this->mock(StripeService::class, function ($mock) use ($cancelAt) {
            $mock->shouldReceive('cancelSubscriptionAtPeriodEnd')
                ->once()
                ->with($this->stripeSubId)
                ->andReturn($this->stripeSubscription(['cancel_at' => $cancelAt]));
        });

        $response = $this->withSession(['auth.user_id' => $this->userId])
            ->post('/member/membership/cancel');

        $response->assertRedirect();

        $sub = Subscription::find($this->subscriptionId);
        $this->assertSame('active', $sub->status, 'cancel keeps access until the period ends');
        $this->assertNotNull($sub->cancelled_at);
        $this->assertSame($cancelAt, $sub->cancelled_at->timestamp);
    }

    public function test_cancel_is_a_no_op_when_already_scheduled(): void
    {
        DB::connection('billing')->table('subscriptions')
            ->where('id', $this->subscriptionId)
            ->update(['cancelled_at' => now()->addDays(10)]);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('cancelSubscriptionAtPeriodEnd');
        });

        $this->withSession(['auth.user_id' => $this->userId])
            ->post('/member/membership/cancel')
            ->assertRedirect();
    }

    public function test_resume_clears_a_scheduled_cancellation(): void
    {
        DB::connection('billing')->table('subscriptions')
            ->where('id', $this->subscriptionId)
            ->update(['cancelled_at' => now()->addDays(10)]);

        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldReceive('resumeSubscription')
                ->once()
                ->with($this->stripeSubId)
                ->andReturn($this->stripeSubscription(['cancel_at_period_end' => false]));
        });

        $this->withSession(['auth.user_id' => $this->userId])
            ->post('/member/membership/resume')
            ->assertRedirect();

        $sub = Subscription::find($this->subscriptionId);
        $this->assertNull($sub->cancelled_at, 'resume clears the scheduled cancel date');
    }

    public function test_resume_errors_when_nothing_is_scheduled(): void
    {
        $this->mock(StripeService::class, function ($mock) {
            $mock->shouldNotReceive('resumeSubscription');
        });

        $this->withSession(['auth.user_id' => $this->userId])
            ->post('/member/membership/resume')
            ->assertSessionHasErrors('membership');
    }
}
