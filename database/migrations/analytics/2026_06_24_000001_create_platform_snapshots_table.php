<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB 8 platform analytics snapshots — append-only rollups powering the admin
 * dashboard, the public homepage counters, and (later) the reporting suite.
 *
 * Two tables, split by sensitivity:
 *   - platform_snapshots : public-safe counts/acres. SELECT to ah_readonly + ah_system.
 *   - revenue_snapshots  : GMV/fees/payouts. SELECT to ah_system ONLY — the public/
 *                          runtime read role (ah_readonly) physically cannot read it.
 *
 * Runs on the `analytics_etl` connection because ah_analytics is owned by ah_etl
 * (not ah_app), and the analytics connections are excluded from the runtime role
 * swap (App\Database\ConnectionRole). Grants are per-table, never ALL TABLES,
 * so revenue is never accidentally exposed to ah_readonly.
 */
return new class extends Migration
{
    protected $connection = 'analytics_etl';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE platform_snapshots (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                captured_at         TIMESTAMPTZ   NOT NULL,
                total_users         INTEGER       NOT NULL DEFAULT 0,
                active_users        INTEGER       NOT NULL DEFAULT 0,
                new_users_30d       INTEGER       NOT NULL DEFAULT 0,
                users_by_type       JSONB         NOT NULL DEFAULT '{}',
                total_properties    INTEGER       NOT NULL DEFAULT 0,
                total_listings      INTEGER       NOT NULL DEFAULT 0,
                active_listings     INTEGER       NOT NULL DEFAULT 0,
                total_leases        INTEGER       NOT NULL DEFAULT 0,
                active_leases       INTEGER       NOT NULL DEFAULT 0,
                leases_by_status    JSONB         NOT NULL DEFAULT '{}',
                total_acres         NUMERIC(14,2) NOT NULL DEFAULT 0,
                huntable_acres      NUMERIC(14,2) NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ   NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_platform_snapshots_captured_at
                ON platform_snapshots (captured_at DESC);

            CREATE TABLE revenue_snapshots (
                id                  UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                captured_at         TIMESTAMPTZ NOT NULL,
                gmv_cents           BIGINT      NOT NULL DEFAULT 0,
                platform_fees_cents BIGINT      NOT NULL DEFAULT 0,
                payouts_cents       BIGINT      NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_revenue_snapshots_captured_at
                ON revenue_snapshots (captured_at DESC);

            -- Read access. ah_system needs CONNECT on the DB (it has none today);
            -- the DB owner (ah_etl, running this migration) can grant it.
            GRANT CONNECT ON DATABASE ah_analytics TO ah_system;
            GRANT USAGE ON SCHEMA public TO ah_readonly, ah_system;

            GRANT SELECT ON platform_snapshots TO ah_readonly, ah_system;
            GRANT SELECT ON revenue_snapshots  TO ah_system;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            REVOKE SELECT ON revenue_snapshots  FROM ah_system;
            REVOKE SELECT ON platform_snapshots FROM ah_readonly, ah_system;
            DROP TABLE IF EXISTS revenue_snapshots;
            DROP TABLE IF EXISTS platform_snapshots;
        SQL);
    }
};
