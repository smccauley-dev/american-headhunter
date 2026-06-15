<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE invoices (
                id                 UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id           UUID         NULL,  -- References DB 3 (Lease) leases.id — null for subscription invoices
                payer_user_id      UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                payee_user_id      UUID         NOT NULL,  -- References DB 1 (Identity) users.id (landowner or platform)
                status             VARCHAR(20)  NOT NULL DEFAULT 'draft'
                                       CHECK (status IN ('draft', 'open', 'paid', 'void', 'uncollectible')),
                subtotal_cents     BIGINT       NOT NULL DEFAULT 0,
                tax_cents          BIGINT       NOT NULL DEFAULT 0,
                platform_fee_cents BIGINT       NOT NULL DEFAULT 0,
                total_cents        BIGINT       NOT NULL DEFAULT 0,
                currency           CHAR(3)      NOT NULL DEFAULT 'USD',
                stripe_invoice_id  VARCHAR(100) NULL,
                due_date           DATE         NULL,
                paid_at            TIMESTAMPTZ  NULL,
                created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at         TIMESTAMPTZ  NULL,

                CONSTRAINT chk_invoices_totals CHECK (total_cents >= 0 AND subtotal_cents >= 0)
            );

            CREATE INDEX idx_invoices_lease_id      ON invoices (lease_id)         WHERE lease_id IS NOT NULL;
            CREATE INDEX idx_invoices_payer_user_id ON invoices (payer_user_id);
            CREATE INDEX idx_invoices_payee_user_id ON invoices (payee_user_id);
            CREATE INDEX idx_invoices_status        ON invoices (status);
            CREATE INDEX idx_invoices_stripe_id     ON invoices (stripe_invoice_id) WHERE stripe_invoice_id IS NOT NULL;
            CREATE INDEX idx_invoices_deleted_at    ON invoices (deleted_at)        WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_invoices_updated_at
                BEFORE UPDATE ON invoices
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;

            CREATE POLICY invoices_parties_and_staff ON invoices
                FOR SELECT TO ah_app
                USING (
                    payer_user_id = current_setting('app.current_user_id', true)::UUID
                    OR payee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS invoices CASCADE;');
    }
};
