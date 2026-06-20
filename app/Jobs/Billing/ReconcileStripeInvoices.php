<?php

namespace App\Jobs\Billing;

use App\Services\Billing\StripeInvoiceProjector;
use App\Services\Billing\StripeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily backstop for the Stripe invoice projection (Phase 5.7). Re-pulls recent
 * subscription invoices and upserts them so a missed or out-of-order webhook is
 * self-healed within the lookback window — refund totals and the captured
 * PaymentIntent are reconciled too.
 *
 * Runs on the standard queue (a maintenance sweep, not user-facing) where the
 * worker connects under the trusted ah_system role, so projection writes are
 * permitted. Stripe stays the source of truth; this only mirrors it.
 */
class ReconcileStripeInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;   // a re-runnable sweep — let the daily schedule retry
    public int $timeout = 600;

    public function __construct(private readonly int $lookbackDays = 45)
    {
        $this->onQueue('default');
    }

    public function handle(StripeService $stripe, StripeInvoiceProjector $projector): void
    {
        if (! config('services.stripe.secret')) {
            Log::info('ReconcileStripeInvoices: skipped — Stripe not configured');
            return;
        }

        $count = $stripe->reconcileInvoiceProjections($projector, $this->lookbackDays);

        Log::info('ReconcileStripeInvoices: reconciled invoices', [
            'count'         => $count,
            'lookback_days' => $this->lookbackDays,
        ]);
    }
}
