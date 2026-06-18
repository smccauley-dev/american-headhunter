<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- Exclusion constraints over a scalar (=) plus a range (&&) need btree_gist.
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            -- Day-hunt listings price per hunter per day (existing price_per_hunter) and
            -- offer a discounted weekly rate applied to each full 7-day block.
            ALTER TABLE property_listings
                ADD COLUMN price_per_hunter_weekly NUMERIC(10,2) NULL;

            -- A booked availability row now carries the booking's cost snapshot, party
            -- size, the lease it belongs to (DB 3), and who created it (DB 1).
            ALTER TABLE property_availability
                ADD COLUMN cost               NUMERIC(10,2) NULL,
                ADD COLUMN hunter_count       INT           NULL,
                ADD COLUMN lease_id           UUID          NULL,  -- References DB 3 (Lease) leases.id
                ADD COLUMN created_by_user_id UUID          NULL;  -- References DB 1 (Identity) users.id

            -- Pre-migration 'booked' rows were blackout markers with no lease or cost
            -- behind them. Reclassify as 'blocked' so they satisfy the new constraint;
            -- real bookings are written by lease activation going forward.
            UPDATE property_availability
                SET reason = 'blocked'
                WHERE reason = 'booked' AND lease_id IS NULL;

            -- Every 'booked' row must trace to a lease and carry a cost; 'blocked' and
            -- 'maintenance' rows are owner-side blackouts with neither.
            ALTER TABLE property_availability
                ADD CONSTRAINT chk_property_availability_booked_lease
                CHECK (
                    (reason =  'booked' AND lease_id IS NOT NULL AND cost IS NOT NULL)
                 OR (reason <> 'booked' AND lease_id IS NULL     AND cost IS NULL)
                );

            -- Exclusive per date: no two ranges for the same listing may overlap
            -- (inclusive bounds — a range ending Aug 7 blocks Aug 7).
            ALTER TABLE property_availability
                ADD CONSTRAINT excl_property_availability_no_overlap
                EXCLUDE USING gist (
                    listing_id WITH =,
                    daterange(date_start, date_end, '[]') WITH &&
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_availability
                DROP CONSTRAINT IF EXISTS excl_property_availability_no_overlap,
                DROP CONSTRAINT IF EXISTS chk_property_availability_booked_lease,
                DROP COLUMN IF EXISTS created_by_user_id,
                DROP COLUMN IF EXISTS lease_id,
                DROP COLUMN IF EXISTS hunter_count,
                DROP COLUMN IF EXISTS cost;

            ALTER TABLE property_listings
                DROP COLUMN IF EXISTS price_per_hunter_weekly;
        SQL);
    }
};
