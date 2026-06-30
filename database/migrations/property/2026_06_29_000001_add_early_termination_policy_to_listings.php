<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-listing early-termination rent policy: when a landowner terminates a lease for
 * a hunter's violation, this governs how much PREPAID RENT is forfeited vs refunded
 * (the security deposit is always forfeited separately). The policy is snapshotted
 * onto each lease at signing, so changing it here only affects new leases.
 *
 *   full_forfeit : hunter forfeits all prepaid rent (landowner keeps it)
 *   prorated     : the unused (future) portion of the term is refunded
 *   full_refund  : all prepaid rent refunded (only the deposit is forfeited)
 */
return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings
                ADD COLUMN early_termination_rent_policy VARCHAR(20) NOT NULL DEFAULT 'full_forfeit'
                    CHECK (early_termination_rent_policy IN ('full_forfeit', 'prorated', 'full_refund'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE property_listings DROP COLUMN IF EXISTS early_termination_rent_policy'
        );
    }
};
