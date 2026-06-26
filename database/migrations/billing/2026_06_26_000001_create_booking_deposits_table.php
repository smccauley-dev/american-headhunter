<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — non-refundable lease booking deposit (down payment). Distinct from
 * the refundable security_deposits collateral: this is earned on booking, credited
 * toward the lease total, and disbursed to the landowner (minus platform fee). The
 * hunter pays it at signing alongside the security deposit (a separate Checkout
 * charge). It has no release/forfeit lifecycle — it resolves from `collected` to
 * `disbursed` once the landowner payout lands.
 *
 * Disbursement is DEFERRED: PayoutService / Stripe Connect is not on this branch,
 * so a collected deposit stays captured on the platform (payout_id NULL) until the
 * Connect work lands and settles it — the same deferral the security-deposit
 * forfeiture path already uses.
 *
 * System-authored, runtime-read-only (SEC-045 — same forgery defense as invoices/
 * payments/payouts/security_deposits): RLS is enabled with a single FOR SELECT
 * policy TO ah_runtime (the two parties + staff) and NO write policy, so the
 * table-level DML grant ah_runtime inherits is inert for writes — every
 * INSERT/UPDATE default-denies. Only ah_system (BYPASSRLS — the booking-deposit
 * webhook, the deferred payout job, the Filament admin panel) authors these rows.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE booking_deposits (
                id                       UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id                 UUID         NOT NULL,  -- References DB 3 (Lease) leases.id
                payer_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (lessee)
                payee_user_id            UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
                payment_id               UUID         NULL REFERENCES payments (id),  -- the charge that funded it
                payout_id                UUID         NULL REFERENCES payouts (id),   -- the landowner disbursement (deferred)
                amount_cents             BIGINT       NOT NULL,
                currency                 CHAR(3)      NOT NULL DEFAULT 'USD',
                status                   VARCHAR(20)  NOT NULL DEFAULT 'collected'
                                             CHECK (status IN ('pending','collected','disbursed')),
                stripe_payment_intent_id VARCHAR(100) NULL,  -- the charge
                collected_at             TIMESTAMPTZ  NULL,
                disbursed_at             TIMESTAMPTZ  NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                -- No deleted_at — a booking deposit is a financial record; it resolves via status, never deletes.

                CONSTRAINT chk_booking_deposits_amount CHECK (amount_cents >= 0)
            );

            CREATE INDEX idx_booking_deposits_lease_id   ON booking_deposits (lease_id);
            CREATE INDEX idx_booking_deposits_payer      ON booking_deposits (payer_user_id);
            CREATE INDEX idx_booking_deposits_payee      ON booking_deposits (payee_user_id);
            CREATE INDEX idx_booking_deposits_status     ON booking_deposits (status);
            CREATE INDEX idx_booking_deposits_payment_id ON booking_deposits (payment_id) WHERE payment_id IS NOT NULL;
            CREATE INDEX idx_booking_deposits_payout_id  ON booking_deposits (payout_id)  WHERE payout_id  IS NOT NULL;

            CREATE TRIGGER trg_booking_deposits_updated_at
                BEFORE UPDATE ON booking_deposits
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE booking_deposits ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: either party (lessee/landowner) sees
            -- their own booking deposit; staff/super_admin see all. No INSERT/UPDATE/
            -- DELETE policy by design — writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY booking_deposits_parties_and_staff ON booking_deposits
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
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS booking_deposits CASCADE;');
    }
};
