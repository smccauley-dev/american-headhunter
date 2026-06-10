<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_availability (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                listing_id  UUID        NOT NULL REFERENCES property_listings (id) ON DELETE CASCADE,
                date_start  DATE        NOT NULL,
                date_end    DATE        NOT NULL,
                reason      VARCHAR(20) NOT NULL DEFAULT 'booked'
                                CHECK (reason IN ('booked', 'blocked', 'maintenance')),
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_property_availability_dates CHECK (date_end >= date_start)
            );

            CREATE INDEX idx_property_availability_listing_id ON property_availability (listing_id);
            CREATE INDEX idx_property_availability_dates      ON property_availability (listing_id, date_start, date_end);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_availability CASCADE');
    }
};
