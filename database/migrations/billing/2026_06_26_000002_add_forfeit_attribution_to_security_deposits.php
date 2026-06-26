<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — forfeiture attribution + provisional Trust Score impact.
 *
 * A forfeiture is the landowner's claim, not an adjudicated fact, so we record
 * WHO is held responsible and keep the hunter's (lessee's) Trust Score penalty
 * PROVISIONAL until an admin confirms it (forfeit_trust_status pending → applied),
 * or waives it (→ waived) when the hunter is exonerated. The landowner-abuse
 * signal (a landowner who forfeits abnormally often) is computed from these rows
 * for the Forfeiture Oversight report — frequency is the tell, not the reason.
 *
 * Single forfeit event per deposit (status guards forfeit() to held-only), so
 * these live as columns on the deposit rather than a separate table.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits
                ADD COLUMN forfeit_category     VARCHAR(30)  NULL
                    CHECK (forfeit_category IN
                        ('property_damage','equipment_damage','rule_violation',
                         'no_show','unpaid_fees','cleaning','other')),
                ADD COLUMN forfeit_fault        VARCHAR(20)  NULL
                    CHECK (forfeit_fault IN ('lessee','landowner_initiated','contested')),
                ADD COLUMN forfeit_initiated_by UUID         NULL,  -- References DB 1 (Identity) users.id (admin actor)
                ADD COLUMN forfeit_trust_status VARCHAR(12)  NULL
                    CHECK (forfeit_trust_status IN ('pending','applied','waived')),
                ADD COLUMN forfeit_resolved_by  UUID         NULL,  -- References DB 1 (Identity) users.id (admin who confirmed/waived)
                ADD COLUMN forfeit_resolved_at  TIMESTAMPTZ  NULL;

            -- Surfaces the still-pending hunter Trust Score penalties an admin must
            -- confirm or waive, and the per-landowner forfeiture rate for the report.
            CREATE INDEX idx_security_deposits_forfeit_trust_status
                ON security_deposits (forfeit_trust_status)
                WHERE forfeit_trust_status IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_security_deposits_forfeit_trust_status;
            ALTER TABLE security_deposits
                DROP COLUMN IF EXISTS forfeit_category,
                DROP COLUMN IF EXISTS forfeit_fault,
                DROP COLUMN IF EXISTS forfeit_initiated_by,
                DROP COLUMN IF EXISTS forfeit_trust_status,
                DROP COLUMN IF EXISTS forfeit_resolved_by,
                DROP COLUMN IF EXISTS forfeit_resolved_at;
        SQL);
    }
};
