<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // No deleted_at — history is preserved; inactive cameras are marked in DB 5 (Wildlife)
        $conn->statement(<<<'SQL'
            CREATE TABLE trail_camera_locations (
                id               UUID     NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                camera_id        UUID     NOT NULL,  -- References DB 5 (Wildlife) trail_cameras.id
                location         GEOMETRY(POINT, 4326) NOT NULL,
                facing_direction SMALLINT NULL,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_trail_camera_locations_direction
                    CHECK (facing_direction IS NULL OR (facing_direction >= 0 AND facing_direction <= 359))
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_trail_camera_locations_camera_id ON trail_camera_locations (camera_id)'
        );
        $conn->statement(
            'CREATE INDEX idx_trail_camera_locations_location_gist ON trail_camera_locations USING GIST (location)'
        );
        $conn->statement(<<<'SQL'
            CREATE TRIGGER trg_trail_camera_locations_updated_at
                BEFORE UPDATE ON trail_camera_locations
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at()
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS trail_camera_locations CASCADE');
    }
};
