<?php

namespace App\Console\Commands;

use App\Models\Billing\StripeInvoiceProjection;
use App\Services\Billing\StripeInvoiceProjector;
use App\Services\Billing\StripeService;
use Illuminate\Console\Command;

/**
 * One-time backfill of the Stripe invoice projection (Phase 5.7) — pulls existing
 * subscription invoices into DB 4 before the read path is switched off live Stripe
 * calls. Reuses the same paginated upsert as the daily ReconcileStripeInvoices job
 * (StripeService::reconcileInvoiceProjections via the shared projector), just over
 * a wide lookback window. Idempotent — safe to re-run; rows upsert on
 * stripe_invoice_id. Runs under ah_system (console), so projection writes succeed.
 *
 *   php artisan billing:backfill-invoice-projection
 *   php artisan billing:backfill-invoice-projection --days=1095
 */
class BackfillInvoiceProjection extends Command
{
    protected $signature   = 'billing:backfill-invoice-projection {--days=730 : How far back to pull invoices, in days}';
    protected $description = 'Backfill the Stripe invoice projection from existing subscription invoices';

    public function handle(StripeService $stripe, StripeInvoiceProjector $projector): int
    {
        if (! config('services.stripe.secret')) {
            $this->error('Stripe is not configured (services.stripe.secret is empty) — nothing to backfill.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        $this->info("Backfilling subscription invoices from the last {$days} day(s)…");

        $count = $stripe->reconcileInvoiceProjections($projector, $days);

        $total = StripeInvoiceProjection::query()->count();

        $this->info("Upserted {$count} invoice(s). Projection now holds {$total} row(s).");

        return self::SUCCESS;
    }
}
