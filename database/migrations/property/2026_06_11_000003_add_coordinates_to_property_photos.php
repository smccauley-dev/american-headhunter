<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_photos
                ADD COLUMN latitude  NUMERIC(9,6) NULL,
                ADD COLUMN longitude NUMERIC(9,6) NULL;

            COMMENT ON COLUMN property_photos.latitude  IS 'Where the photo was taken (WGS84) — display only. Auto-extracted from EXIF GPS on upload or set manually. Never used for spatial queries; use DB 13 for that.';
            COMMENT ON COLUMN property_photos.longitude IS 'Where the photo was taken (WGS84) — display only. Negative values are West.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_photos
                DROP COLUMN IF EXISTS latitude,
                DROP COLUMN IF EXISTS longitude;
        SQL);
    }
};
