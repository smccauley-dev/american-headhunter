<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_applications
                ADD COLUMN property_id_snapshot       UUID         NULL,
                ADD COLUMN property_title_snapshot    VARCHAR(255) NULL,
                ADD COLUMN property_slug_snapshot     VARCHAR(255) NULL,
                ADD COLUMN property_location_snapshot VARCHAR(150) NULL,
                ADD COLUMN listing_season_start_snap  DATE         NULL,
                ADD COLUMN listing_season_end_snap    DATE         NULL;

            COMMENT ON COLUMN lease_applications.property_id_snapshot       IS 'Snapshot of property UUID at application time — survives listing archival';
            COMMENT ON COLUMN lease_applications.property_title_snapshot    IS 'Snapshot of property title at application time';
            COMMENT ON COLUMN lease_applications.property_slug_snapshot     IS 'Snapshot of property slug at application time — used to build public URLs';
            COMMENT ON COLUMN lease_applications.property_location_snapshot IS 'Snapshot of "{county} County, {state_code}" at application time';
            COMMENT ON COLUMN lease_applications.listing_season_start_snap  IS 'Snapshot of listing season_start at application time';
            COMMENT ON COLUMN lease_applications.listing_season_end_snap    IS 'Snapshot of listing season_end at application time';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_applications
                DROP COLUMN IF EXISTS property_id_snapshot,
                DROP COLUMN IF EXISTS property_title_snapshot,
                DROP COLUMN IF EXISTS property_slug_snapshot,
                DROP COLUMN IF EXISTS property_location_snapshot,
                DROP COLUMN IF EXISTS listing_season_start_snap,
                DROP COLUMN IF EXISTS listing_season_end_snap;
        SQL);
    }
};
