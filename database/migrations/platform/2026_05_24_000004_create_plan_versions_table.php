<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE plan_versions (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                plan_id                 UUID NOT NULL REFERENCES membership_plans (id),
                version_number          INTEGER NOT NULL,

                plan_key                VARCHAR(50) NOT NULL,
                display_name            VARCHAR(100) NOT NULL,
                monthly_price_cents     INTEGER NOT NULL,
                annual_price_cents      INTEGER NOT NULL,
                platform_fee_pct        DECIMAL(5,2),
                commission_pct          DECIMAL(5,2),
                stripe_price_id_monthly VARCHAR(100),
                stripe_price_id_annual  VARCHAR(100),

                -- Full entitlement snapshot at this version (allows offline resolution without joining)
                entitlements_snapshot   JSONB NOT NULL DEFAULT '{}',

                effective_from          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                superseded_at           TIMESTAMPTZ,

                change_reason           TEXT,
                created_by_user_id      UUID,           -- References DB 1 (Identity) users.id

                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                -- No updated_at — plan versions are immutable once created

                CONSTRAINT uq_plan_versions_plan_version UNIQUE (plan_id, version_number)
            );

            CREATE INDEX idx_plan_versions_plan_id ON plan_versions (plan_id);
            CREATE INDEX idx_plan_versions_effective ON plan_versions (plan_id, effective_from DESC);
            CREATE INDEX idx_plan_versions_current ON plan_versions (plan_id)
                WHERE superseded_at IS NULL;

            -- Immutability rule: once created, plan_versions rows cannot be updated
            CREATE RULE plan_versions_no_update AS ON UPDATE TO plan_versions DO INSTEAD NOTHING;

            -- Seed v1 for every plan (prices match membership_plans seed above)
            INSERT INTO plan_versions (plan_id, version_number, plan_key, display_name, monthly_price_cents, annual_price_cents, entitlements_snapshot, change_reason)
            SELECT
                id,
                1,
                plan_key,
                display_name,
                monthly_price_cents,
                annual_price_cents,
                '{}'::jsonb,
                'Initial version'
            FROM membership_plans;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP RULE IF EXISTS plan_versions_no_update ON plan_versions;
            DROP TABLE IF EXISTS plan_versions CASCADE;
        SQL);
    }
};
