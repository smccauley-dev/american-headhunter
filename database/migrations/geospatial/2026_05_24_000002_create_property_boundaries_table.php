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
            CREATE TABLE property_boundaries (
                id          UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id UUID NOT NULL,  -- References DB 2 (Property) properties.id
                boundary    GEOMETRY(MULTIPOLYGON, 4326) NOT NULL,
                area_acres  NUMERIC(12,4) GENERATED ALWAYS AS (
                                ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
                            ) STORED,
                source      VARCHAR(20) NOT NULL DEFAULT 'manual',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at  TIMESTAMPTZ NULL,

                CONSTRAINT chk_property_boundaries_source
                    CHECK (source IN ('manual', 'gps_import', 'parcel_data'))
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_property_boundaries_property_id ON property_boundaries (property_id) WHERE deleted_at IS NULL'
        );
        $conn->statement(
            'CREATE INDEX idx_property_boundaries_boundary_gist ON property_boundaries USING GIST (boundary) WHERE deleted_at IS NULL'
        );
        $conn->statement(<<<'SQL'
            CREATE TRIGGER trg_property_boundaries_updated_at
                BEFORE UPDATE ON property_boundaries
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at()
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_boundaries CASCADE');
    }
};
