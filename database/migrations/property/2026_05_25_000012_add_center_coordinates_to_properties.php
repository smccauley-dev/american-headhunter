<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE properties
                ADD COLUMN center_lat NUMERIC(9,6) NULL,
                ADD COLUMN center_lng NUMERIC(9,6) NULL;

            COMMENT ON COLUMN properties.center_lat IS 'Approximate center latitude (WGS84) — display only. Derived from boundary centroid or manually set by landowner. Never used for spatial queries; use DB 13 property_boundaries for that.';
            COMMENT ON COLUMN properties.center_lng IS 'Approximate center longitude (WGS84) — display only. Negative values are West.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE properties
                DROP COLUMN IF EXISTS center_lat,
                DROP COLUMN IF EXISTS center_lng;
        SQL);
    }
};
