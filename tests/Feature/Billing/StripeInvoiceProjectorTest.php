<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\StripeInvoiceProjection;
use App\Services\Billing\StripeInvoiceProjector;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5.7 — the shared invoice→projection mapping used by both the webhook and
 * the daily reconcile job. Runs against the real `billing`/`platform`
 * connections; rows are force-deleted in tearDown.
 */
class StripeInvoiceProjectorTest extends TestCase
{
    private StripeInvoiceProjector $projector;

    /** @var array<int,string> */ private array $invoiceIds = [];
    /** @var array<int,string> */ private array $subscriptionIds = [];

    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = app(StripeInvoiceProjector::class);
        $this->versionId = app(SubscriptionService::class)->currentVersionForPlan('hunter_scout')->id;
    }

    protected function tearDown(): void
    {
        $billing = DB::connection('billing');
        if ($this->invoiceIds) {
            $billing->table('stripe_invoice_projections')->whereIn('stripe_invoice_id', $this->invoiceIds)->delete();
        }
        if ($this->subscriptionIds) {
            $billing->table('subscriptions')->whereIn('id', $this->subscriptionIds)->delete();
        }
        try { $billing->disconnect(); } catch (\Throwable) {}
        parent::tearDown();
    }

    /** A Stripe subscription invoice payload (dahlia shape). */
    private function invoicePayload(string $userId, string $invoiceId, string $status = 'paid', array $overrides = []): array
    {
        $this->invoiceIds[] = $invoiceId;

        return array_merge([
            'id'                 => $invoiceId,
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

    public function test_upserts_a_subscription_invoice(): void
    {
        $userId    = (string) Str::uuid();
        $invoiceId = 'in_' . Str::random(14);

        $row = $this->projector->upsert($this->invoicePayload($userId, $invoiceId), 'pi_abc');

        $this->assertNotNull($row);
        $this->assertSame($userId, $row->subscriber_user_id);
        $this->assertSame('paid', $row->status);
        $this->assertSame(999, $row->amount_cents);
        $this->assertSame('USD', $row->currency);
        $this->assertSame('pi_abc', $row->stripe_payment_intent_id);
        $this->assertNotNull($row->period_start);
    }

    public function test_returns_null_for_non_subscription_invoice(): void
    {
        $invoiceId = 'in_' . Str::random(14);
        $payload   = $this->invoicePayload((string) Str::uuid(), $invoiceId);
        unset($payload['parent']);

        $this->assertNull($this->projector->upsert($payload));
        $this->assertNull(StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->first());
    }

    public function test_falls_back_to_local_subscription_when_metadata_missing(): void
    {
        $userId = (string) Str::uuid();
        $subId  = 'sub_' . Str::random(14);

        $sub = app(SubscriptionService::class)->start($userId, $this->versionId, [
            'stripe_subscription_id' => $subId,
            'stripe_customer_id'     => 'cus_fallback',
            'status'                 => 'active',
        ]);
        $this->subscriptionIds[] = $sub->id;

        $invoiceId = 'in_' . Str::random(14);
        $payload   = $this->invoicePayload($userId, $invoiceId, 'paid', [
            'parent' => [
                'type'                 => 'subscription_details',
                'subscription_details' => ['subscription' => $subId], // no metadata
            ],
        ]);

        $row = $this->projector->upsert($payload);

        $this->assertNotNull($row, 'subscriber resolves from the local subscription');
        $this->assertSame($userId, $row->subscriber_user_id);
    }

    public function test_is_idempotent_on_stripe_invoice_id(): void
    {
        $userId    = (string) Str::uuid();
        $invoiceId = 'in_' . Str::random(14);

        $this->projector->upsert($this->invoicePayload($userId, $invoiceId, 'open'));
        $this->projector->upsert($this->invoicePayload($userId, $invoiceId, 'paid'), 'pi_z');

        $rows = StripeInvoiceProjection::where('stripe_invoice_id', $invoiceId)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('paid', $rows->first()->status);
        $this->assertSame('pi_z', $rows->first()->stripe_payment_intent_id);
    }

    public function test_applies_full_and_partial_refund_totals(): void
    {
        $userId    = (string) Str::uuid();
        $invoiceId = 'in_' . Str::random(14);

        $full = $this->projector->upsert(
            $this->invoicePayload($userId, $invoiceId),
            'pi_r',
            ['refunded_cents' => 999, 'charged_cents' => 999],
        );
        $this->assertSame(999, $full->amount_refunded_cents);
        $this->assertSame('full', $full->refund_status);

        $partial = $this->projector->upsert(
            $this->invoicePayload($userId, $invoiceId),
            'pi_r',
            ['refunded_cents' => 300, 'charged_cents' => 999],
        );
        $this->assertSame(300, $partial->amount_refunded_cents);
        $this->assertSame('partial', $partial->refund_status);
    }

    public function test_null_refund_leaves_existing_refund_fields_untouched(): void
    {
        $userId    = (string) Str::uuid();
        $invoiceId = 'in_' . Str::random(14);

        $this->projector->upsert(
            $this->invoicePayload($userId, $invoiceId),
            'pi_keep',
            ['refunded_cents' => 999, 'charged_cents' => 999],
        );

        // A plain invoice.* upsert (no refund arg) must not wipe the refund.
        $row = $this->projector->upsert($this->invoicePayload($userId, $invoiceId), 'pi_keep');

        $this->assertSame(999, $row->amount_refunded_cents);
        $this->assertSame('full', $row->refund_status);
    }
}
