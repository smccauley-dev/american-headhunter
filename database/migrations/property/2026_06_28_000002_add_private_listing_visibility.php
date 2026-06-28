<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add a `private` (Private / Hidden) visibility so a landowner can PAUSE a
 * listing — pulling it from every public surface (home, search, and its own
 * detail page) without deleting it. Unlike `leased`, a paused listing is fully
 * hidden; resuming flips it back to `public`. Existing rows are unaffected.
 */
return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_visibility_check;

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_visibility_check
                CHECK (visibility IN ('public', 'members_only', 'invite_only', 'private'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_listings DROP CONSTRAINT IF EXISTS property_listings_visibility_check;

            -- A paused listing reverts to public when the option is removed.
            UPDATE property_listings SET visibility = 'public' WHERE visibility = 'private';

            ALTER TABLE property_listings
                ADD CONSTRAINT property_listings_visibility_check
                CHECK (visibility IN ('public', 'members_only', 'invite_only'));
        SQL);
    }
};
