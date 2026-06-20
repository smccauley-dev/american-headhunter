<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.7 slice 2 — store the invoice's PaymentIntent on the projection.
 *
 * The dahlia API (2026-05-27) dropped the invoice back-reference from BOTH the
 * Charge (charge.invoice) and the PaymentIntent (payment_intent.invoice), so a
 * `charge.refunded` webhook — which carries only the PaymentIntent id — has no
 * way to find the invoice it refunded. We capture the PI on the row at
 * invoice.paid time (the only point it exists) so the refund event can map back
 * here with a local lookup instead of a Stripe round-trip.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE stripe_invoice_projections
                ADD COLUMN stripe_payment_intent_id VARCHAR(100) NULL;

            CREATE INDEX idx_sip_stripe_payment_intent_id
                ON stripe_invoice_projections (stripe_payment_intent_id)
                WHERE stripe_payment_intent_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_sip_stripe_payment_intent_id;
            ALTER TABLE stripe_invoice_projections DROP COLUMN IF EXISTS stripe_payment_intent_id;
        SQL);
    }
};
