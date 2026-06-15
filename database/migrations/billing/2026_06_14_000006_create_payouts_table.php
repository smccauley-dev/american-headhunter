<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE payouts (
                id                 UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                payee_user_id      UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner)
                stripe_account_id  VARCHAR(100) NOT NULL,  -- The landowner's Stripe Connect account ID
                amount_cents       BIGINT       NOT NULL,
                currency           CHAR(3)      NOT NULL DEFAULT 'USD',
                status             VARCHAR(15)  NOT NULL DEFAULT 'pending'
                                       CHECK (status IN ('pending', 'in_transit', 'paid', 'failed', 'cancelled')),
                stripe_payout_id   VARCHAR(100) NULL,
                stripe_transfer_id VARCHAR(100) NULL,
                scheduled_for      DATE         NULL,
                paid_at            TIMESTAMPTZ  NULL,
                created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_payouts_payee_user_id   ON payouts (payee_user_id);
            CREATE INDEX idx_payouts_status          ON payouts (status);
            CREATE INDEX idx_payouts_stripe_payout   ON payouts (stripe_payout_id)   WHERE stripe_payout_id IS NOT NULL;
            CREATE INDEX idx_payouts_stripe_transfer ON payouts (stripe_transfer_id) WHERE stripe_transfer_id IS NOT NULL;
            CREATE INDEX idx_payouts_scheduled_for   ON payouts (scheduled_for)      WHERE scheduled_for IS NOT NULL;

            CREATE TRIGGER trg_payouts_updated_at
                BEFORE UPDATE ON payouts
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE payouts ENABLE ROW LEVEL SECURITY;

            CREATE POLICY payouts_own_user ON payouts
                FOR SELECT TO ah_app
                USING (
                    payee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS payouts CASCADE;');
    }
};
