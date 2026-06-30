<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot of the listing's early-termination rent policy at signing time. A lease
 * keeps the policy that was in effect when it was signed even if the landowner later
 * changes the listing (grandfathering). Drives LeaseService::terminateForViolation.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE leases
                ADD COLUMN early_termination_rent_policy VARCHAR(20) NOT NULL DEFAULT 'full_forfeit'
                    CHECK (early_termination_rent_policy IN ('full_forfeit', 'prorated', 'full_refund'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE leases DROP COLUMN IF EXISTS early_termination_rent_policy'
        );
    }
};
