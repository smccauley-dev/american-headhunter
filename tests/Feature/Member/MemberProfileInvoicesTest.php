<?php

namespace Tests\Feature\Member;

use App\Services\Billing\StripeInvoiceProjector;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\EntitlementService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 5.7 (read-path cutover) — the member billing history is now served from
 * the local DB 4 projection (StripeInvoiceProjection) instead of a live Stripe
 * call per page render. This drives the real /member/profile route end to end
 * and asserts the projected row reaches the Inertia `invoices` prop in the
 * shape the React billing tab expects.
 *
 * Rows live on the real billing/identity connections and are removed in
 * tearDown; the entitlement cache is invalidated for the test user.
 */
class MemberProfileInvoicesTest extends TestCase
{
    private string $userId;
    private string $invoiceId;
    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();

        // /member/profile is a GET with no throttle, but bypass it defensively
        // so a stray limiter counter in Valkey can never flake the suite.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->userId    = (string) Str::uuid();
        $this->invoiceId = 'in_' . Str::random(14);
        $this->versionId = app(SubscriptionService::class)
            ->currentVersionForPlan('hunter_scout')->id;

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
        DB::connection('billing')->table('stripe_invoice_projections')
            ->where('stripe_invoice_id', $this->invoiceId)->delete();
        DB::connection('identity')->table('users')->where('id', $this->userId)->delete();
        app(EntitlementService::class)->invalidateForUser($this->userId);

        parent::tearDown();
    }

    private function seedProjection(): void
    {
        app(StripeInvoiceProjector::class)->upsert([
            'id'                 => $this->invoiceId,
            'number'             => 'INV-PROJ01',
            'status'             => 'paid',
            'amount_due'         => 999,
            'amount_paid'        => 999,
            'currency'           => 'usd',
            'customer'           => 'cus_' . Str::random(10),
            'period_start'       => now()->timestamp,
            'period_end'         => now()->addMonth()->timestamp,
            'created'            => now()->timestamp,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/hosted',
            'invoice_pdf'        => 'https://invoice.stripe.com/i/hosted/pdf',
            'parent'             => [
                'type'                 => 'subscription_details',
                'subscription_details' => [
                    'subscription' => 'sub_' . Str::random(14),
                    'metadata'     => ['user_id' => $this->userId, 'plan_version_id' => $this->versionId],
                ],
            ],
        ], 'pi_proj');
    }

    public function test_profile_serves_the_projected_invoice(): void
    {
        $this->seedProjection();

        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Profile/Hunter', false)
                ->has('invoices', 1)
                ->where('invoices.0.id', $this->invoiceId)
                ->where('invoices.0.number', 'INV-PROJ01')
                ->where('invoices.0.amount', '9.99')
                ->where('invoices.0.amount_cents', 999)
                ->where('invoices.0.currency', 'USD')
                ->where('invoices.0.status', 'paid')
                ->where('invoices.0.refund_status', 'none')
                ->where('invoices.0.hosted_url', 'https://invoice.stripe.com/i/hosted')
            );
    }

    public function test_profile_invoices_are_empty_without_a_projection(): void
    {
        $this->withSession(['auth.user_id' => $this->userId])
            ->get('/member/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member/Profile/Hunter', false)
                ->where('invoices', [])
            );
    }
}
