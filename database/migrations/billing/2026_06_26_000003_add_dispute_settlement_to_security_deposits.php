<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — defer forfeiture settlement to a terminal outcome + insurance routing.
 *
 * A forfeiture is now a CLAIM: forfeit() records the intended amount but moves no
 * money and the deposit stays 'held' until an admin adjudicates a contest, a party
 * opts out via insurance, or the contest deadline lapses. So the forfeit_trust_status
 * lifecycle grows two terminal states beyond pending/applied/waived:
 *   - 'opted_out' — settled outside the dispute system because a party carries
 *     insurance; no Trust Score change for either side.
 *   - 'reversed'  — an admin override that restores an already-applied hunter penalty.
 *
 * forfeit_contest_deadline drives the auto-finalize job (past-deadline uncontested
 * forfeitures finalize as upheld).
 *
 * Insurance is recorded inline on the financial record: when either party has a
 * certificate of insurance on file, a covered loss is settled by the insurer and
 * never becomes a fault penalty. coi_document_id references a DB 11 document tagged
 * insurance_certificate (bare UUID ref — never a SQL foreign key).
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits
                DROP CONSTRAINT IF EXISTS security_deposits_forfeit_trust_status_check;
            ALTER TABLE security_deposits
                ADD CONSTRAINT security_deposits_forfeit_trust_status_check
                    CHECK (forfeit_trust_status IN ('pending','applied','waived','opted_out','reversed'));

            ALTER TABLE security_deposits
                ADD COLUMN forfeit_contest_deadline TIMESTAMPTZ NULL,
                ADD COLUMN insurance_covered_party  VARCHAR(12)  NULL
                    CHECK (insurance_covered_party IN ('landowner','hunter','none')),
                ADD COLUMN insurer_name             VARCHAR(120) NULL,
                ADD COLUMN policy_number            VARCHAR(80)  NULL,
                ADD COLUMN coi_document_id          UUID         NULL,  -- References DB 11 (Documents) documents.id (insurance_certificate)
                ADD COLUMN coverage_status          VARCHAR(12)  NULL
                    CHECK (coverage_status IN ('none','claimed','covered','denied'));

            -- Surfaces pending forfeiture-claims whose contest window is open, for the
            -- auto-finalize job to sweep once the deadline lapses.
            CREATE INDEX idx_security_deposits_forfeit_contest_deadline
                ON security_deposits (forfeit_contest_deadline)
                WHERE forfeit_trust_status = 'pending';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_security_deposits_forfeit_contest_deadline;
            ALTER TABLE security_deposits
                DROP COLUMN IF EXISTS forfeit_contest_deadline,
                DROP COLUMN IF EXISTS insurance_covered_party,
                DROP COLUMN IF EXISTS insurer_name,
                DROP COLUMN IF EXISTS policy_number,
                DROP COLUMN IF EXISTS coi_document_id,
                DROP COLUMN IF EXISTS coverage_status;

            ALTER TABLE security_deposits
                DROP CONSTRAINT IF EXISTS security_deposits_forfeit_trust_status_check;
            ALTER TABLE security_deposits
                ADD CONSTRAINT security_deposits_forfeit_trust_status_check
                    CHECK (forfeit_trust_status IN ('pending','applied','waived'));
        SQL);
    }
};
