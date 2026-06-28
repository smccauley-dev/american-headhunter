<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.5 — lease-rent collection via Stripe Connect destination charges. The
 * customer pays on the platform account; transfer_data[destination] routes the net
 * to the landowner's connected account, application_fee_amount is the platform's
 * cut, and on_behalf_of attributes settlement + the 1099-K to the landowner. This
 * row is the single source of truth for one such charge — gross (what the customer
 * paid), surcharge (the processing-fee recovery), application_fee (platform
 * revenue), and net (the auto-transferred amount the landowner received).
 *
 * System-authored, runtime-read-only (SEC-045, same as security_deposits/payouts):
 * RLS is enabled with a single FOR SELECT policy TO ah_runtime (the two parties +
 * staff) and NO write policy, so the table-level DML grant ah_runtime inherits is
 * inert for writes — every INSERT/UPDATE default-denies. Only ah_system (BYPASSRLS
 * — the lease-payment webhook, the db.system return route, and the Filament admin
 * panel) authors these rows. A logged-in payer or landowner reads their own
 * payment but can never forge or mutate one.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_payments (
                id                       UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                 UUID         NOT NULL,  -- References DB 3 (Lease) leases.id
                payer_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (lessee)
                payee_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
                stripe_account_id        VARCHAR(100) NOT NULL,  -- the landowner's Connect account (transfer destination)
                gross_cents              BIGINT       NOT NULL,  -- rent_balance + surcharge — what the customer paid
                surcharge_cents          BIGINT       NOT NULL DEFAULT 0,  -- processing-fee recovery (kept by platform)
                application_fee_cents     BIGINT       NOT NULL,  -- platform revenue (tier fee + surcharge)
                net_cents                BIGINT       NOT NULL,  -- auto-transferred to the landowner
                currency                 CHAR(3)      NOT NULL DEFAULT 'USD',
                status                   VARCHAR(18)  NOT NULL DEFAULT 'collected'
                                             CHECK (status IN ('collected','refunded','partially_refunded')),
                stripe_payment_intent_id VARCHAR(100) NOT NULL,
                stripe_charge_id         VARCHAR(100) NULL,
                stripe_transfer_id       VARCHAR(100) NULL,  -- the destination transfer Stripe auto-creates
                paid_at                  TIMESTAMPTZ  NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                -- No deleted_at — a lease payment is a financial record; it resolves via status, never deletes.

                CONSTRAINT chk_lease_payments_amounts
                    CHECK (gross_cents >= 0
                           AND surcharge_cents >= 0
                           AND application_fee_cents >= 0
                           AND net_cents >= 0)
            );

            CREATE UNIQUE INDEX uq_lease_payments_payment_intent ON lease_payments (stripe_payment_intent_id);
            CREATE INDEX idx_lease_payments_lease_id ON lease_payments (lease_id);
            CREATE INDEX idx_lease_payments_payer    ON lease_payments (payer_user_id);
            CREATE INDEX idx_lease_payments_payee    ON lease_payments (payee_user_id);
            CREATE INDEX idx_lease_payments_status   ON lease_payments (status);

            CREATE TRIGGER trg_lease_payments_updated_at
                BEFORE UPDATE ON lease_payments
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE lease_payments ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: either party (payer/landowner) sees
            -- their own payment; staff/super_admin see all. No INSERT/UPDATE/DELETE
            -- policy by design — writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY lease_payments_parties_and_staff ON lease_payments
                FOR SELECT TO ah_runtime
                USING (
                    payer_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR payee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_payments CASCADE;');
    }
};
