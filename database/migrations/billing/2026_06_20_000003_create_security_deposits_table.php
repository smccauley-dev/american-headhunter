<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — refundable lease security deposit. Funded by a dedicated `payments`
 * row (separate charge + refund-on-release; the Stripe manual-capture auth-hold
 * path was rejected because its 7-day window cannot span a season-long lease).
 * At lease end the deposit is released, partially withheld, or forfeited; forfeited
 * funds disburse to the landowner via a `payouts` row.
 *
 * System-authored, runtime-read-only (same forgery-defense as invoices/payments/
 * payouts and the Stripe invoice projection — SEC-045): RLS is enabled with a
 * single FOR SELECT policy TO ah_runtime (the two parties + staff) and NO write
 * policy, so the table-level DML grant ah_runtime inherits via ALTER DEFAULT
 * PRIVILEGES is inert for writes — every INSERT/UPDATE default-denies. Only
 * ah_system (BYPASSRLS — the deposit-charge webhook, release/forfeit jobs, and
 * the Filament admin panel) authors these rows. A logged-in lessee or landowner
 * can read their own deposit but can never forge or mutate one.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE security_deposits (
                id                       UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                 UUID         NOT NULL,  -- References DB 3 (Lease) leases.id
                payer_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (lessee)
                payee_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
                payment_id               UUID         NULL REFERENCES payments (id),  -- the charge that funded the hold
                amount_cents             BIGINT       NOT NULL,
                refunded_amount_cents    BIGINT       NOT NULL DEFAULT 0,
                forfeited_amount_cents   BIGINT       NOT NULL DEFAULT 0,
                currency                 CHAR(3)      NOT NULL DEFAULT 'USD',
                status                   VARCHAR(20)  NOT NULL DEFAULT 'pending'
                                             CHECK (status IN
                                                 ('pending','held','partially_released',
                                                  'released','forfeited','refunded')),
                forfeit_reason           VARCHAR(200) NULL,
                stripe_payment_intent_id VARCHAR(100) NULL,  -- the hold/charge
                stripe_refund_id         VARCHAR(100) NULL,  -- the release refund
                held_at                  TIMESTAMPTZ  NULL,
                released_at              TIMESTAMPTZ  NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                -- No deleted_at — a deposit is a financial record; it resolves via status, never deletes.

                CONSTRAINT chk_security_deposits_amounts
                    CHECK (amount_cents >= 0
                           AND refunded_amount_cents >= 0
                           AND forfeited_amount_cents >= 0
                           AND refunded_amount_cents + forfeited_amount_cents <= amount_cents)
            );

            CREATE INDEX idx_security_deposits_lease_id   ON security_deposits (lease_id);
            CREATE INDEX idx_security_deposits_payer      ON security_deposits (payer_user_id);
            CREATE INDEX idx_security_deposits_payee      ON security_deposits (payee_user_id);
            CREATE INDEX idx_security_deposits_status     ON security_deposits (status);
            CREATE INDEX idx_security_deposits_payment_id ON security_deposits (payment_id) WHERE payment_id IS NOT NULL;

            CREATE TRIGGER trg_security_deposits_updated_at
                BEFORE UPDATE ON security_deposits
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE security_deposits ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: either party (lessee/landowner) sees
            -- their own deposit; staff/super_admin see all. No INSERT/UPDATE/DELETE
            -- policy by design — writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY security_deposits_parties_and_staff ON security_deposits
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
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS security_deposits CASCADE;');
    }
};
