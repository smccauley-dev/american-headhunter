<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // SEC-061: a harvest photo mirrored into the gallery with its EXIF GPS
        // retained ("keep location data") must never become publicly servable,
        // even when the member's gallery visibility is public — the coordinates
        // pinpoint an on-property harvest spot (SEC-024). The public profile
        // photo list/serve path (Phase 7+) must exclude rows where this is TRUE.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE profile_photos
                ADD COLUMN is_location_private BOOLEAN NOT NULL DEFAULT FALSE;

            COMMENT ON COLUMN profile_photos.is_location_private IS
                'TRUE when the underlying image retains location metadata (EXIF GPS). Never publicly servable regardless of gallery visibility (SEC-061 / SEC-024).';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'ALTER TABLE profile_photos DROP COLUMN IF EXISTS is_location_private'
        );
    }
};
