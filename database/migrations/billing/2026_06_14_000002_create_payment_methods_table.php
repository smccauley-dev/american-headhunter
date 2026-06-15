<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE payment_methods (
                id                        UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                   UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                stripe_payment_method_id  VARCHAR(100) NOT NULL,
                type                      VARCHAR(20)  NOT NULL
                                              CHECK (type IN ('card', 'bank_account', 'us_bank_account')),
                brand                     VARCHAR(20)  NULL,
                last_four                 CHAR(4)      NULL,
                exp_month                 SMALLINT     NULL CHECK (exp_month BETWEEN 1 AND 12),
                exp_year                  SMALLINT     NULL,
                is_default                BOOLEAN      NOT NULL DEFAULT false,
                created_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at                TIMESTAMPTZ  NULL
            );

            CREATE UNIQUE INDEX uq_payment_methods_stripe_pm ON payment_methods (stripe_payment_method_id);
            CREATE        INDEX idx_payment_methods_user_id  ON payment_methods (user_id);
            CREATE        INDEX idx_payment_methods_deleted  ON payment_methods (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_payment_methods_updated_at
                BEFORE UPDATE ON payment_methods
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE payment_methods ENABLE ROW LEVEL SECURITY;

            CREATE POLICY payment_methods_own_user ON payment_methods
                FOR ALL TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS payment_methods CASCADE;');
    }
};
