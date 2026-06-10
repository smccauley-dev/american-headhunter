<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        $conn->statement(<<<'SQL'
            CREATE TABLE stand_locations (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id  UUID         NOT NULL,  -- References DB 2 (Property) properties.id
                lease_id     UUID         NULL,       -- References DB 3 (Lease) leases.id — NULL = visible to all lessees
                name         VARCHAR(100) NOT NULL,
                stand_type   VARCHAR(20)  NOT NULL,
                location     GEOMETRY(POINT, 4326) NOT NULL,
                elevation_ft SMALLINT     NULL,
                notes        TEXT         NULL,
                is_active    BOOLEAN      NOT NULL DEFAULT true,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at   TIMESTAMPTZ  NULL,

                CONSTRAINT chk_stand_locations_type
                    CHECK (stand_type IN (
                        'ladder', 'climbing', 'ground_blind', 'box_blind', 'tripod', 'shooting_house'
                    ))
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_stand_locations_property_id ON stand_locations (property_id) WHERE deleted_at IS NULL'
        );
        $conn->statement(
            'CREATE INDEX idx_stand_locations_lease_id ON stand_locations (lease_id) WHERE lease_id IS NOT NULL AND deleted_at IS NULL'
        );
        $conn->statement(
            'CREATE INDEX idx_stand_locations_location_gist ON stand_locations USING GIST (location) WHERE deleted_at IS NULL'
        );
        $conn->statement(<<<'SQL'
            CREATE TRIGGER trg_stand_locations_updated_at
                BEFORE UPDATE ON stand_locations
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at()
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS stand_locations CASCADE');
    }
};
