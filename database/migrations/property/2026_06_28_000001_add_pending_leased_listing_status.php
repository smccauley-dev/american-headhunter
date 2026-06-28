<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split the listing lifecycle so an exclusive (annual/seasonal) listing reflects
 * the lease it is committed to: `pending` while the lease awaits signatures and
 * `leased` once it is executed — replacing the single, ambiguous `sold_out`.
 * Existing `sold_out` rows were already-leased listings, so they migrate to
 * `leased`. Day-hunt listings are unaffected (they reserve per-date and stay
 * `active`).
 */
return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_status_check;

            UPDATE property_listings SET status = 'leased' WHERE status = 'sold_out';

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_status_check
                CHECK (status IN ('draft', 'active', 'pending', 'leased', 'expired', 'archived'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_status_check;

            -- Collapse the split back onto the original enum: a leased listing was
            -- sold_out; a pending one returns to the market.
            UPDATE property_listings SET status = 'sold_out' WHERE status = 'leased';
            UPDATE property_listings SET status = 'active'   WHERE status = 'pending';

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_status_check
                CHECK (status IN ('draft', 'active', 'sold_out', 'expired', 'archived'));
        SQL);
    }
};
