<?php

namespace Tests\Feature\Billing;

use App\Jobs\Billing\ProcessStripeWebhook;
use App\Models\Billing\StripeInvoiceProjection;
use App\Models\Billing\Subscription;
use App\Services\Audit\AuditService;
use App\Services\Billing\StripeService;
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
    /** @var array<int,string> */ private array $invoiceIds = [];
    /** @var array<int,string> */ private array $depositPaymentIntentIds = [];

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
        if ($this->invoiceIds) {
            $billing->table('stripe_invoice_projections')->whereIn('stripe_invoice_id', $this->invoiceIds)->delete();
        }
        if ($this->depositPaymentIntentIds) {
            $billing->table('security_deposits')->whereIn('stripe_payment_intent_id', $this->depositPaymentIntentIds)->delete();
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

    private function dispatch(string $type, array $object, ?StripeService $stripe = null): void
    {
        $job = new ProcessStripeWebhook('evt_' . bin2hex(random_bytes(8)), $type, $object);
        $job->handle(
            app(EntitlementService::class),
            app(AuditService::class),
            app(SubscriptionService::class),
            $stripe ?? app(StripeService::class),
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

    // ── customer.subscription.updated — plan-change reconcile ────────────────────

    public function test_subscription_updated_reconciles_plan_version_from_metadata(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_change',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        // A different current version (a second plan) the webhook can reconcile to.
        $newVersionId = app(SubscriptionService::class)
            ->currentVersionForPlan('hunter_pro')->id;
        $this->assertNotSame($this->versionId, $newVersionId);

        $this->dispatch('customer.subscription.updated', [
            'id'       => $subId,
            'status'   => 'active',
            'metadata' => ['plan_version_id' => $newVersionId],
        ]);

        $sub->refresh();
        $this->assertSame($newVersionId, $sub->plan_version_id, 'plan_version_id reconciles to Stripe metadata');
    }

    public function test_subscription_updated_keeps_plan_version_when_metadata_matches(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_same',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        $this->dispatch('customer.subscription.updated', [
            'id'       => $subId,
            'status'   => 'active',
            'metadata' => ['plan_version_id' => $this->versionId],
        ]);

        $sub->refresh();
        $this->assertSame($this->versionId, $sub->plan_version_id, 'matching metadata is a no-op');
    }

    // ── checkout.session.completed — setup mode (dunning card update) ────────────

    public function test_checkout_completed_setup_mode_applies_updated_payment_method(): void
    {
        $stripe = \Mockery::mock(StripeService::class);
        $stripe->shouldReceive('applyUpdatedPaymentMethod')
            ->once()
            ->with('seti_test_123');

        $this->dispatch('checkout.session.completed', [
            'mode'         => 'setup',
            'setup_intent' => 'seti_test_123',
        ], $stripe);
    }

    // ── checkout.session.completed — payment mode (security deposit) ─────────────

    public function test_checkout_completed_payment_mode_records_held_deposit(): void
    {
        $payerId = $this->newUserId();
        $payeeId = $this->newUserId();
        $leaseId = (string) Str::uuid();
        $pi      = 'pi_dep_' . Str::random(12);
        $this->depositPaymentIntentIds[] = $pi;

        $this->dispatch('checkout.session.completed', [
            'mode'           => 'payment',
            'payment_intent' => $pi,
            'currency'       => 'usd',
            'amount_total'   => 7500,
            'metadata'       => [
                'purpose'       => 'security_deposit',
                'lease_id'      => $leaseId,
                'payer_user_id' => $payerId,
                'payee_user_id' => $payeeId,
                'amount_cents'  => '7500',
            ],
        ]);

        $deposit = \App\Models\Billing\SecurityDeposit::where('stripe_payment_intent_id', $pi)->first();
        $this->assertNotNull($deposit, 'a held deposit is authored by the webhook (ah_system)');
        $this->assertSame('held', $deposit->status);
        $this->assertSame(7500, (int) $deposit->amount_cents);
        $this->assertSame($leaseId, $deposit->lease_id);
    }

    public function test_checkout_completed_payment_mode_ignores_non_deposit(): void
    {
        $userId = $this->newUserId();

        // mode=payment with no security_deposit purpose must not author a deposit.
        $this->dispatch('checkout.session.completed', [
            'mode'           => 'payment',
            'payment_intent' => 'pi_other_' . Str::random(8),
            'metadata'       => ['purpose' => 'something_else', 'user_id' => $userId],
        ]);

        $this->assertSame(0, \App\Models\Billing\SecurityDeposit::where('lease_id', $userId)->count());
        $this->assertTrue(true);
    }

    // ── invoice.* — Stripe invoice projection (Phase 5.7) ───────────────────────

    /**
     * A Stripe subscription invoice payload. The dahlia API nests the subscription
     * id and our checkout metadata under parent.subscription_details, and there is
     * no top-level `subscription` key.
     */
    private function invoiceObject(string $userId, string $invoiceId, string $status = 'paid', array $overrides = []): array
    {
        $this->invoiceIds[] = $invoiceId;

        return array_merge([
            'id'                 => $invoiceId,
            'object'             => 'invoice',
            'number'             => 'INV-' . strtoupper(Str::random(6)),
            'status'             => $status,
            'amount_due'         => 999,
            'amount_paid'        => $status === 'paid' ? 999 : 0,
            'currency'           => 'usd',
            'customer'           => 'cus_' . Str::random(10),
            'period_start'       => now()->timestamp,
            'period_end'         => now()->addMonth()->timestamp,
            'created'            => now()->timestamp,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/' . Str::random(8),
            'invoice_pdf'        => 'https://invoice.stripe.com/i/' . Str::random(8) . '/pdf',
            'parent'             => [
                'type'                 => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_' . Str::random(14),
                    'metadata'     => ['user_id' => $userId, 'plan_version_id' => $this->versionId],
                ],
            ],
        ], $overrides);
    }

    private function stripeReturningPaymentIntent(string $pi): StripeService
    {
        $stripe = \Mockery::mock(StripeService::class);
        $stripe->shouldReceive('invoicePaymentIntentIdFor')->andReturn($pi);

        return $stripe;
    }

    public function test_invoice_paid_creates_projection_with_payment_intent(): void
    {
        $userId    = $this->newUserId();
        $invoiceId = 'in_' . Str::random(14);

        $this->dispatch(
            'invoice.paid',
            $this->invoiceObject($userId, $invoiceId, 'paid'),
            $this->stripeReturningPaymentIntent('pi_captured_123'),
        );

        $row = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first();
        $this->assertNotNull($row, 'a projection row is created for a paid subscription invoice');
        $this->assertSame($userId, $row->subscriber_user_id, 'subscriber comes from parent.subscription_details.metadata');
        $this->assertSame('paid', $row->status);
        $this->assertSame(999, $row->amount_cents);
        $this->assertSame('USD', $row->currency);
        $this->assertNotNull($row->hosted_invoice_url);
        $this->assertSame('pi_captured_123', $row->stripe_payment_intent_id, 'the PaymentIntent is captured at paid time');
    }

    public function test_invoice_upsert_is_idempotent_and_advances_status(): void
    {
        $userId    = $this->newUserId();
        $invoiceId = 'in_' . Str::random(14);

        // First the invoice is finalized (open), then paid — the same row is reused.
        $this->dispatch('invoice.finalized', $this->invoiceObject($userId, $invoiceId, 'open'), app(StripeService::class));
        $this->dispatch('invoice.paid', $this->invoiceObject($userId, $invoiceId, 'paid'), $this->stripeReturningPaymentIntent('pi_x'));

        $rows = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->get();
        $this->assertCount(1, $rows, 'a replayed/advanced invoice never creates a duplicate row');
        $this->assertSame('paid', $rows->first()->status);
        $this->assertSame('pi_x', $rows->first()->stripe_payment_intent_id);
    }

    public function test_invoice_voided_sets_void_status(): void
    {
        $userId    = $this->newUserId();
        $invoiceId = 'in_' . Str::random(14);

        $this->dispatch('invoice.voided', $this->invoiceObject($userId, $invoiceId, 'void'), app(StripeService::class));

        $row = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first();
        $this->assertNotNull($row);
        $this->assertSame('void', $row->status);
    }

    public function test_non_subscription_invoice_is_ignored(): void
    {
        $userId    = $this->newUserId();
        $invoiceId = 'in_' . Str::random(14);

        // A one-off invoice carries no parent.subscription_details.
        $object = $this->invoiceObject($userId, $invoiceId, 'paid');
        unset($object['parent']);

        $this->dispatch('invoice.paid', $object, app(StripeService::class));

        $this->assertNull(StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first());
    }

    public function test_invoice_payment_failed_marks_past_due_and_projects(): void
    {
        $userId = $this->newUserId();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_fail',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        $invoiceId = 'in_' . Str::random(14);
        $object    = $this->invoiceObject($userId, $invoiceId, 'open', [
            'parent' => [
                'type'                 => 'subscription_details',
                'subscription_details' => [
                    'subscription' => $subId,
                    'metadata'     => ['user_id' => $userId, 'plan_version_id' => $this->versionId],
                ],
            ],
        ]);

        $this->dispatch('invoice.payment_failed', $object, app(StripeService::class));

        $sub->refresh();
        $this->assertSame('past_due', $sub->status, 'the subscription is resolved via parent.subscription_details and marked past_due');

        $row = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first();
        $this->assertNotNull($row, 'the failed invoice is still projected');
        $this->assertSame('open', $row->status);
    }

    public function test_charge_refunded_updates_projection(): void
    {
        $userId = $this->newUserId();
        $pi     = 'pi_refund_' . Str::random(10);

        $invoiceId = 'in_' . Str::random(14);
        $this->invoiceIds[] = $invoiceId;
        StripeInvoiceProjection::create([
            'subscriber_user_id'       => $userId,
            'stripe_invoice_id'        => $invoiceId,
            'stripe_payment_intent_id' => $pi,
            'status'                   => 'paid',
            'amount_cents'             => 999,
            'currency'                 => 'USD',
        ]);

        // Full refund.
        $this->dispatch('charge.refunded', [
            'object'          => 'charge',
            'payment_intent'  => $pi,
            'amount'          => 999,
            'amount_refunded' => 999,
            'refunded'        => true,
        ]);

        $row = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first();
        $this->assertSame(999, $row->amount_refunded_cents);
        $this->assertSame('full', $row->refund_status);

        // A partial refund on the same row resolves to "partial".
        $this->dispatch('charge.refunded', [
            'object'          => 'charge',
            'payment_intent'  => $pi,
            'amount'          => 999,
            'amount_refunded' => 400,
            'refunded'        => false,
        ]);

        $row->refresh();
        $this->assertSame(400, $row->amount_refunded_cents);
        $this->assertSame('partial', $row->refund_status);
    }
}
