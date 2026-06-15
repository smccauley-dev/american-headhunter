<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE payments (
                id                        UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                invoice_id                UUID         NOT NULL REFERENCES invoices (id),
                payer_user_id             UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                amount_cents              BIGINT       NOT NULL,
                currency                  CHAR(3)      NOT NULL DEFAULT 'USD',
                status                    VARCHAR(15)  NOT NULL DEFAULT 'pending'
                                              CHECK (status IN ('pending', 'succeeded', 'failed', 'refunded', 'disputed')),
                payment_method_id         UUID         NULL REFERENCES payment_methods (id),
                stripe_payment_intent_id  VARCHAR(100) NULL,
                stripe_charge_id          VARCHAR(100) NULL,
                failure_reason            VARCHAR(200) NULL,
                metadata                  JSONB        NOT NULL DEFAULT '{}',
                created_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_payments_invoice_id       ON payments (invoice_id);
            CREATE INDEX idx_payments_payer_user_id    ON payments (payer_user_id);
            CREATE INDEX idx_payments_status           ON payments (status);
            CREATE INDEX idx_payments_stripe_pi_id     ON payments (stripe_payment_intent_id)
                WHERE stripe_payment_intent_id IS NOT NULL;
            CREATE INDEX idx_payments_stripe_charge_id ON payments (stripe_charge_id)
                WHERE stripe_charge_id IS NOT NULL;

            CREATE TRIGGER trg_payments_updated_at
                BEFORE UPDATE ON payments
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE payments ENABLE ROW LEVEL SECURITY;

            CREATE POLICY payments_own_user ON payments
                FOR SELECT TO ah_app
                USING (
                    payer_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS payments CASCADE;');
    }
};
