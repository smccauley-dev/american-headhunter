<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE stripe_accounts (
                id                      UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                 UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                stripe_account_id       VARCHAR(100) NOT NULL,
                charges_enabled         BOOLEAN      NOT NULL DEFAULT false,
                payouts_enabled         BOOLEAN      NOT NULL DEFAULT false,
                details_submitted       BOOLEAN      NOT NULL DEFAULT false,
                onboarding_completed_at TIMESTAMPTZ  NULL,
                created_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_stripe_accounts_user_id        ON stripe_accounts (user_id);
            CREATE UNIQUE INDEX uq_stripe_accounts_stripe_acct_id ON stripe_accounts (stripe_account_id);

            CREATE TRIGGER trg_stripe_accounts_updated_at
                BEFORE UPDATE ON stripe_accounts
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS stripe_accounts CASCADE;');
    }
};
