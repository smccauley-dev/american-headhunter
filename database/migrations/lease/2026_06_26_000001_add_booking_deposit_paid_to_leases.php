<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        // Mirror of the non-refundable booking deposit paid for this lease (the
        // source of truth is the booking_deposits row in DB 4). Kept here for
        // at-a-glance display and to compute the remaining balance the hunter still
        // owes (total_price - booking_deposit_paid). Cross-DB mirror, best-effort.
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE leases
                ADD COLUMN IF NOT EXISTS booking_deposit_paid NUMERIC(10,2) NOT NULL DEFAULT 0.00;

            COMMENT ON COLUMN leases.booking_deposit_paid
                IS 'Non-refundable booking deposit paid, in dollars. Credited toward total_price. Mirror of DB 4 booking_deposits.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE leases DROP COLUMN IF EXISTS booking_deposit_paid;
        SQL);
    }
};
