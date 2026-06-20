<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        // is_featured marks a staff-curated "advertising" listing surfaced on the
        // public home page. Unlike search results (gated behind signup), featured
        // listings are fully viewable to anyone. Set by admins only.
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_listings
                ADD COLUMN IF NOT EXISTS is_featured BOOLEAN NOT NULL DEFAULT false;

            COMMENT ON COLUMN property_listings.is_featured
                IS 'Staff-flagged advertising/featured listing — shown fully viewable on the public home page.';

            CREATE INDEX IF NOT EXISTS idx_property_listings_featured
                ON property_listings (is_featured)
                WHERE is_featured = true AND deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP INDEX IF EXISTS idx_property_listings_featured;
            ALTER TABLE property_listings DROP COLUMN IF EXISTS is_featured;
        SQL);
    }
};
