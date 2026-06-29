<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vet-first booking fee (Slice 2). Once the winning applicant pays the booking fee,
 * a lease is created with a 7-day completion window: the parties must sign and the
 * lessee must pay the full lease balance within it. The deadline-enforcement command
 * forfeits the held booking fee to the landowner (the listing was off-market) and
 * cancels the lease if the window lapses while it is still pending_signatures or
 * pending_payment. Cleared when the lease activates.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE leases
                ADD COLUMN completion_deadline TIMESTAMPTZ NULL;

            CREATE INDEX idx_leases_completion_deadline
                ON leases (completion_deadline)
                WHERE completion_deadline IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_leases_completion_deadline;
            ALTER TABLE leases DROP COLUMN IF EXISTS completion_deadline;
        SQL);
    }
};
