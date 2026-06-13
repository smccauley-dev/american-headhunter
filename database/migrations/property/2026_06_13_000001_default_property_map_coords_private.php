<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    // SEC-024: GPS coordinates auto-extracted from EXIF were published to the
    // public listing by default. Flip the default to private (opt-in) and reset
    // existing rows, which were never an explicit admin choice.
    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_images
                ALTER COLUMN show_coords_publicly SET DEFAULT false;

            UPDATE property_map_images SET show_coords_publicly = false;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_images
                ALTER COLUMN show_coords_publicly SET DEFAULT true;
        SQL);
    }
};
