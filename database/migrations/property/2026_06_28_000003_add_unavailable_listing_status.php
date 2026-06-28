<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add an `unavailable` listing status: the landowner keeps the listing posted and
 * publicly visible (it stays in browse, search, and at its detail URL) but marks
 * it as not currently open for application — it is not leased, pending, or active.
 * Unlike a paused listing (visibility='private', which is hidden everywhere), an
 * unavailable listing is shown with a "Not Currently Available" badge and no Apply.
 */
return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_status_check;

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_status_check
                CHECK (status IN ('draft', 'active', 'pending', 'leased', 'unavailable', 'expired', 'archived'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_status_check;

            -- An unavailable listing was simply off the market; return it to active.
            UPDATE property_listings SET status = 'active' WHERE status = 'unavailable';

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_status_check
                CHECK (status IN ('draft', 'active', 'pending', 'leased', 'expired', 'archived'));
        SQL);
    }
};
