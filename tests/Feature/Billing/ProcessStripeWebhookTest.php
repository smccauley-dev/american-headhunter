<?php

namespace Tests\Feature\Billing;

use App\Jobs\Billing\ProcessStripeWebhook;
use App\Models\Billing\Subscription;
use App\Services\Audit\AuditService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.3 — webhook reconciliation of hosted Checkout into a local
 * subscription, and the round-trip cancel from Stripe's side.
 *
 * Runs against the real `billing`/`platform` connections (the job and services
 * declare those explicitly). Created rows are force-deleted in tearDown and the
 * entitlement cache is invalidated. Each call uses a fresh event id so the
 * Valkey dedupe guard never short-circuits an assertion we don't intend.
 */
class ProcessStripeWebhookTest extends TestCase
{
    /** @var array<int,string> */ private array $subscriptionIds = [];
    /** @var array<int,string> */ private array $userIds = [];

    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();
        // A real, current (non-superseded) version the webhook can lock onto.
        $this->versionId = app(SubscriptionService::class)
            ->currentVersionForPlan('hunter_scout')->id;
    }

    protected function tearDown(): void
    {
        $billing = DB::connection('billing');
        if ($this->subscriptionIds) {
            $billing->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete();
        }

        $entitlements = app(EntitlementService::class);
        foreach ($this->userIds as $uid) {
            $entitlements->invalidateForUser($uid);
        }

        try { $billing->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    private function newUserId(): string
    {
        $id = (string) Str::uuid();
        $this->userIds[] = $id;

        return $id;
    }

    private function dispatch(string $type, array $object): void
    {
        $job = new ProcessStripeWebhook('evt_' . bin2hex(random_bytes(8)), $type, $object);
        $job->handle(
            app(EntitlementService::class),
            app(AuditService::class),
            app(SubscriptionService::class),
        );
    }

    private function checkoutObject(string $userId, string $subId, string $custId = 'cus_test'): array
    {
        return [
            'mode'         => 'subscription',
            'subscription' => $subId,
            'customer'     => $custId,
            'metadata'     => ['user_id' => $userId, 'plan_version_id' => $this->versionId],
        ];
    }

    // ── checkout.session.completed ──────────────────────────────────────────────

    public function test_checkout_completed_creates_local_subscription(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $this->dispatch('checkout.session.completed', $this->checkoutObject($userId, $subId, 'cus_abc'));

        $sub = Subscription::where('user_id', $userId)->first();
        $this->assertNotNull($sub, 'a local subscription is created');
        $this->subscriptionIds[] = $sub->id;

        $this->assertSame('active', $sub->status);
        $this->assertSame($this->versionId, $sub->plan_version_id, 'locks the version carried in metadata');
        $this->assertSame($subId, $sub->getRawOriginal('stripe_subscription_id'));
        $this->assertSame('cus_abc', $sub->getRawOriginal('stripe_customer_id'));
    }

    public function test_checkout_completed_ignores_non_subscription_mode(): void
    {
        $userId = $this->newUserId();
        $object = $this->checkoutObject($userId, 'sub_x');
        $object['mode'] = 'payment';

        $this->dispatch('checkout.session.completed', $object);

        $this->assertNull(Subscription::where('user_id', $userId)->first());
    }

    public function test_checkout_completed_skips_missing_metadata(): void
    {
        $userId = $this->newUserId();
        $object = $this->checkoutObject($userId, 'sub_y');
        unset($object['metadata']['plan_version_id']);

        $this->dispatch('checkout.session.completed', $object);

        $this->assertNull(Subscription::where('user_id', $userId)->first());
    }

    public function test_checkout_completed_is_idempotent_on_stripe_subscription_id(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);
        $object = $this->checkoutObject($userId, $subId);

        $this->dispatch('checkout.session.completed', $object);
        // Replay as a *different* Stripe event carrying the same subscription id.
        $this->dispatch('checkout.session.completed', $object);

        $rows = Subscription::where('stripe_subscription_id', $subId)->get();
        $this->assertCount(1, $rows, 'a replayed checkout never creates a duplicate subscription');
        $this->subscriptionIds[] = $rows->first()->id;
    }

    // ── customer.subscription.deleted ───────────────────────────────────────────

    public function test_subscription_deleted_cancels_local_subscription(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_del',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        $this->dispatch('customer.subscription.deleted', ['id' => $subId]);

        $sub->refresh();
        $this->assertSame('cancelled', $sub->status);
        $this->assertNotNull($sub->cancelled_at);
    }

    public function test_subscription_deleted_no_op_for_unknown_subscription(): void
    {
        // No local row for this id — must not throw.
        $this->dispatch('customer.subscription.deleted', ['id' => 'sub_unknown_' . Str::random(8)]);
        $this->assertTrue(true);
    }

    // ── customer.subscription.updated — scheduled cancel round-trip ──────────────

    public function test_subscription_updated_records_scheduled_cancel(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_sched',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        $cancelAt = now()->addDays(20)->timestamp;
        $this->dispatch('customer.subscription.updated', [
            'id'                   => $subId,
            'status'               => 'active',
            'cancel_at_period_end' => true,
            'cancel_at'            => $cancelAt,
            'current_period_end'   => $cancelAt,
        ]);

        $sub->refresh();
        $this->assertSame('active', $sub->status, 'a scheduled cancel keeps the subscription active');
        $this->assertNotNull($sub->cancelled_at, 'the scheduled cancel date is recorded');
        $this->assertSame($cancelAt, $sub->cancelled_at->timestamp);
    }

    public function test_subscription_updated_clears_cancel_on_resume(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_resume',
            'status'                 => 'active',
        ]);
        $sub->cancelled_at = now()->addDays(10);
        $sub->save();
        $this->subscriptionIds[] = $sub->id;

        // Stripe fires .updated with the flag cleared when the member resumes.
        $this->dispatch('customer.subscription.updated', [
            'id'                   => $subId,
            'status'               => 'active',
            'cancel_at_period_end' => false,
        ]);

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->cancelled_at, 'resuming clears the scheduled cancel date');
    }
}
