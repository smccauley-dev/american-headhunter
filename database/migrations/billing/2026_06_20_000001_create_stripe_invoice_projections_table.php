<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.7 — local read model of Stripe SUBSCRIPTION invoices. Kept current by
 * webhooks (record-level upserts) with a daily reconciliation backstop, so the
 * admin invoice list and member billing history read from DB 4 instead of making
 * live Stripe round-trips. Stripe stays the source of truth; this is a mirror.
 *
 * Constraint 1 (system-authored, runtime-read-only): RLS is enabled with a single
 * FOR SELECT policy TO ah_runtime (subscriber + staff) and NO write policy. The
 * table-level DML grant ah_runtime inherits via ALTER DEFAULT PRIVILEGES is
 * therefore inert for writes — every INSERT/UPDATE default-denies. Only ah_system
 * (BYPASSRLS — the webhook worker + reconcile job) writes these rows. Same
 * forgery-defense shape as invoices/payments/payouts (SEC-045).
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE stripe_invoice_projections (
                id                     UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                subscriber_user_id     UUID         NOT NULL,  -- References DB 1 (Identity) users.id — the member billed
                stripe_invoice_id      VARCHAR(100) NOT NULL,
                stripe_subscription_id VARCHAR(100) NULL,
                stripe_customer_id     VARCHAR(100) NULL,
                number                 VARCHAR(100) NULL,       -- Stripe-assigned invoice number
                status                 VARCHAR(20)  NOT NULL DEFAULT 'draft'
                                           CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible')),
                amount_cents           BIGINT       NOT NULL DEFAULT 0,
                amount_refunded_cents  BIGINT       NOT NULL DEFAULT 0,
                currency               CHAR(3)      NOT NULL DEFAULT 'USD',
                refund_status          VARCHAR(10)  NOT NULL DEFAULT 'none'
                                           CHECK (refund_status IN ('none', 'partial', 'full')),
                period_start           TIMESTAMPTZ  NULL,
                period_end             TIMESTAMPTZ  NULL,
                hosted_invoice_url     TEXT         NULL,
                invoice_pdf            TEXT         NULL,
                stripe_created_at      TIMESTAMPTZ  NULL,       -- Stripe's invoice creation time (distinct from our created_at)
                created_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at             TIMESTAMPTZ  NULL,

                CONSTRAINT chk_sip_amounts CHECK (amount_cents >= 0 AND amount_refunded_cents >= 0)
            );

            CREATE UNIQUE INDEX uq_sip_stripe_invoice_id  ON stripe_invoice_projections (stripe_invoice_id);
            CREATE INDEX idx_sip_subscriber_user_id       ON stripe_invoice_projections (subscriber_user_id);
            CREATE INDEX idx_sip_stripe_subscription_id   ON stripe_invoice_projections (stripe_subscription_id) WHERE stripe_subscription_id IS NOT NULL;
            CREATE INDEX idx_sip_stripe_customer_id       ON stripe_invoice_projections (stripe_customer_id)     WHERE stripe_customer_id IS NOT NULL;
            CREATE INDEX idx_sip_status                   ON stripe_invoice_projections (status);
            CREATE INDEX idx_sip_deleted_at               ON stripe_invoice_projections (deleted_at)             WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_sip_updated_at
                BEFORE UPDATE ON stripe_invoice_projections
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE stripe_invoice_projections ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: the subscriber sees their own rows;
            -- staff/super_admin see all. No INSERT/UPDATE/DELETE policy by design —
            -- writes are system-authored (ah_system, BYPASSRLS) only.
            CREATE POLICY sip_subscriber_and_staff ON stripe_invoice_projections
                FOR SELECT TO ah_runtime
                USING (
                    subscriber_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS stripe_invoice_projections CASCADE;');
    }
};
