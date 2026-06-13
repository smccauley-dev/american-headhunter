<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_images
                ADD COLUMN show_coords_publicly BOOLEAN NOT NULL DEFAULT true;

            COMMENT ON COLUMN property_map_images.show_coords_publicly IS 'Whether the map''s GPS reference point is displayed on the public listing (boundary map only).';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_images DROP COLUMN IF EXISTS show_coords_publicly;
        SQL);
    }
};
