<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.5 (Slice 1.5) — configurable processing-fee schedule (DB 4).
 *
 * American Headhunter is the merchant of record (separate charges & transfers),
 * so it pays Stripe's processing fee on every charge. A processing fee recovers
 * that cost as a customer-facing surcharge, configurable per transaction category
 * and (optionally) per state. The platform fee (plan_versions.platform_fee_pct)
 * is a separate, tier-based deduction from the landowner's net and is NOT modeled
 * here.
 *
 * Resolution: most-specific match wins — an exact state_code row beats a NULL-state
 * (all-states) row for the same category. One active row per (category, state)
 * window is enforced by a partial unique index.
 *
 * Config table, admin-managed: like the other billing tables it is system-authored
 * (writes only via ah_system — the Filament admin panel) and runtime-readable (RLS
 * SELECT TO ah_runtime USING (true), since the surcharge is computed on the member
 * checkout path). No write policy, so ah_runtime's inherited DML grant is inert for
 * INSERT/UPDATE/DELETE.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE fee_schedules (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                transaction_category VARCHAR(24)  NOT NULL
                                         CHECK (transaction_category IN
                                             ('lease','auction','outfitter_booking',
                                              'security_deposit','marketplace')),
                state_code           CHAR(2)      NULL,   -- NULL = applies to all states
                pct                  NUMERIC(6,4) NULL,   -- percent, e.g. 2.9000
                flat_cents           BIGINT       NULL,   -- fixed surcharge in cents
                payer                VARCHAR(12)  NOT NULL DEFAULT 'customer'
                                         CHECK (payer IN ('customer','landowner')),
                description          VARCHAR(200) NULL,
                is_active            BOOLEAN      NOT NULL DEFAULT true,
                effective_from       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                effective_to         TIMESTAMPTZ  NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at           TIMESTAMPTZ  NULL,

                -- A fee must define at least one of a percent or a flat amount.
                CONSTRAINT chk_fee_schedules_has_amount
                    CHECK (pct IS NOT NULL OR flat_cents IS NOT NULL),
                CONSTRAINT chk_fee_schedules_nonneg
                    CHECK ((pct IS NULL OR pct >= 0) AND (flat_cents IS NULL OR flat_cents >= 0))
            );

            -- One active fee per (category, state) at a time. COALESCE folds the
            -- NULL-state (all-states) row into the uniqueness check so two active
            -- all-states rows for the same category can't coexist.
            CREATE UNIQUE INDEX uq_fee_schedules_active_scope
                ON fee_schedules (transaction_category, COALESCE(state_code, '00'))
                WHERE is_active AND deleted_at IS NULL;

            CREATE INDEX idx_fee_schedules_lookup
                ON fee_schedules (transaction_category, state_code)
                WHERE deleted_at IS NULL;

            CREATE TRIGGER trg_fee_schedules_updated_at
                BEFORE UPDATE ON fee_schedules
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE fee_schedules ENABLE ROW LEVEL SECURITY;

            -- Global config: readable by any runtime request (the surcharge is
            -- computed when a member initiates checkout). No write policy — rows are
            -- authored only by ah_system (Filament admin), mirroring the other
            -- system-authored billing tables (SEC-045).
            CREATE POLICY fee_schedules_readable ON fee_schedules
                FOR SELECT TO ah_runtime
                USING (true);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS fee_schedules CASCADE;');
    }
};
