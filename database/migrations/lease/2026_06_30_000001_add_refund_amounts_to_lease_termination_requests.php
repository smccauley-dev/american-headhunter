<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Persist what was refunded to the hunter when a landowner decides an
 * early-termination request, so the hunter's lease page can show the outcome
 * (and the figures) after the fact. lease_payments tracks only a refund STATUS,
 * not the partial amount, so a custom/prorated rent refund cannot be
 * reconstructed later — we snapshot both amounts here at decision time.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_termination_requests
                ADD COLUMN deposit_refunded_cents BIGINT NULL,  -- goodwill deposit returned to the hunter on approval
                ADD COLUMN rent_refunded_cents    BIGINT NULL;  -- prepaid rent returned to the hunter on approval
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_termination_requests
                DROP COLUMN IF EXISTS deposit_refunded_cents,
                DROP COLUMN IF EXISTS rent_refunded_cents;
        SQL);
    }
};
