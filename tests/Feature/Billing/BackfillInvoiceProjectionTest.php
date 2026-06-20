<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\StripeService;
use Mockery;
use Tests\TestCase;

/**
 * Phase 5.7 — wiring for the one-time backfill command. The mapping/pull is
 * covered by StripeInvoiceProjectorTest + the reconcile service; here we only
 * assert the command bails when Stripe is unconfigured and otherwise delegates
 * to reconcileInvoiceProjections with the requested lookback.
 */
class BackfillInvoiceProjectionTest extends TestCase
{
    public function test_fails_when_stripe_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldNotReceive('reconcileInvoiceProjections');
        $this->app->instance(StripeService::class, $stripe);

        $this->artisan('billing:backfill-invoice-projection')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(1);
    }

    public function test_backfills_with_default_lookback(): void
    {
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('reconcileInvoiceProjections')
            ->once()
            ->with(Mockery::type(\App\Services\Billing\StripeInvoiceProjector::class), 730)
            ->andReturn(3);
        $this->app->instance(StripeService::class, $stripe);

        $this->artisan('billing:backfill-invoice-projection')
            ->expectsOutputToContain('Upserted 3 invoice(s)')
            ->assertExitCode(0);
    }

    public function test_passes_custom_days(): void
    {
        config(['services.stripe.secret' => 'sk_test_dummy']);

        $stripe = Mockery::mock(StripeService::class);
        $stripe->shouldReceive('reconcileInvoiceProjections')
            ->once()
            ->with(Mockery::type(\App\Services\Billing\StripeInvoiceProjector::class), 1095)
            ->andReturn(0);
        $this->app->instance(StripeService::class, $stripe);

        $this->artisan('billing:backfill-invoice-projection --days=1095')
            ->assertExitCode(0);
    }
}
