<?php

namespace Tests\Feature\Billing;

use App\Jobs\Billing\ReconcileStripeInvoices;
use App\Services\Billing\StripeInvoiceProjector;
use App\Services\Billing\StripeService;
use Mockery;
use Tests\TestCase;

/**
 * Phase 5.7 — wiring for the daily reconcile backstop. The mapping itself is
 * covered by StripeInvoiceProjectorTest; here we only assert the job delegates
 * to StripeService with the right lookback and no-ops when Stripe is unconfigured.
 */
class ReconcileStripeInvoicesTest extends TestCase
{
    public function test_skips_when_stripe_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('reconcileInvoiceProjections');

        (new ReconcileStripeInvoices)->handle($stripe, app(StripeInvoiceProjector::class));
    }

    public function test_invokes_service_with_default_lookback_when_configured(): void
    {
        config(['services.stripe.secret' => 'sk_test_dummy']);
        $projector = app(StripeInvoiceProjector::class);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('reconcileInvoiceProjections')
            ->once()
            ->with($projector, 45)
            ->andReturn(7);

        (new ReconcileStripeInvoices)->handle($stripe, $projector);
    }

    public function test_passes_custom_lookback(): void
    {
        config(['services.stripe.secret' => 'sk_test_dummy']);
        $projector = app(StripeInvoiceProjector::class);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('reconcileInvoiceProjections')
            ->once()
            ->with($projector, 10)
            ->andReturn(0);

        (new ReconcileStripeInvoices(10))->handle($stripe, $projector);
    }
}
