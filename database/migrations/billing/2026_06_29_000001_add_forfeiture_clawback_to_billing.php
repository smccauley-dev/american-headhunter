<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forfeiture-reversal clawback support (DB 4).
 *
 * When a forfeited deposit was disbursed to the landowner via a Stripe Connect
 * transfer and the forfeiture is later reversed (the hunter is exonerated), the
 * transfer must be reversed so the landowner is not left overpaid. To do that the
 * deposit needs a handle on the disbursing payout, and the payout needs a terminal
 * 'reversed' state.
 */
return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits
                ADD COLUMN forfeit_payout_id UUID NULL;  -- References DB 4 (Billing) payouts.id

            ALTER TABLE payouts
                DROP CONSTRAINT IF EXISTS payouts_status_check;
            ALTER TABLE payouts
                ADD CONSTRAINT payouts_status_check
                CHECK (status IN ('pending', 'in_transit', 'paid', 'failed', 'cancelled', 'reversed'));
            ALTER TABLE payouts
                ADD COLUMN reversed_at TIMESTAMPTZ NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE security_deposits DROP COLUMN IF EXISTS forfeit_payout_id;

            ALTER TABLE payouts DROP COLUMN IF EXISTS reversed_at;
            ALTER TABLE payouts DROP CONSTRAINT IF EXISTS payouts_status_check;
            ALTER TABLE payouts
                ADD CONSTRAINT payouts_status_check
                CHECK (status IN ('pending', 'in_transit', 'paid', 'failed', 'cancelled'));
        SQL);
    }
};
