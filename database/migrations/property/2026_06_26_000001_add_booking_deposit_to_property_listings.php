<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        // A non-refundable booking deposit (down payment) the hunter pays at signing,
        // alongside the refundable security deposit. Set by the landowner on the
        // listing as a flat amount or a percent of the lease total (mutually
        // exclusive — mirrors deposit_amount / deposit_percent). Credited toward the
        // lease total; never released or forfeited (it is earned on booking).
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_listings
                ADD COLUMN IF NOT EXISTS booking_deposit_amount  NUMERIC(10,2) NULL,
                ADD COLUMN IF NOT EXISTS booking_deposit_percent SMALLINT      NULL
                    CHECK (booking_deposit_percent BETWEEN 0 AND 100);

            COMMENT ON COLUMN property_listings.booking_deposit_amount
                IS 'Flat non-refundable booking deposit (down payment), in dollars. Mutually exclusive with booking_deposit_percent.';
            COMMENT ON COLUMN property_listings.booking_deposit_percent
                IS 'Non-refundable booking deposit as a percent of the lease total. Mutually exclusive with booking_deposit_amount.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_listings DROP COLUMN IF EXISTS booking_deposit_percent;
            ALTER TABLE property_listings DROP COLUMN IF EXISTS booking_deposit_amount;
        SQL);
    }
};
