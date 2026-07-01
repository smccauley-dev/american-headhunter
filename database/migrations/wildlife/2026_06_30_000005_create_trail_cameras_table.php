<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE trail_cameras (
                id                     UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id               UUID         NOT NULL,  -- References DB 3 (Lease) leases.id
                property_id            UUID         NOT NULL,  -- References DB 2 (Property) properties.id
                user_id                UUID         NOT NULL,  -- References DB 1 (Identity) users.id — camera owner
                name                   VARCHAR(100) NOT NULL,
                model                  VARCHAR(100) NULL,
                location_geospatial_id UUID         NULL,  -- References DB 13 (Geospatial) stand_locations.id
                status                 VARCHAR(10)  NOT NULL DEFAULT 'active'
                                           CHECK (status IN ('active', 'offline', 'inactive')),
                last_photo_at          TIMESTAMPTZ  NULL,
                created_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at             TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_trail_cameras_lease_id    ON trail_cameras (lease_id);
            CREATE INDEX idx_trail_cameras_property_id ON trail_cameras (property_id);
            CREATE INDEX idx_trail_cameras_user_id     ON trail_cameras (user_id);
            CREATE INDEX idx_trail_cameras_status      ON trail_cameras (status);
            CREATE INDEX idx_trail_cameras_deleted_at  ON trail_cameras (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER trg_trail_cameras_updated_at
                BEFORE UPDATE ON trail_cameras
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS trail_cameras CASCADE');
    }
};
