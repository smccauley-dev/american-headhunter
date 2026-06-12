<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_map_images (
                id          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                document_id UUID         NOT NULL,  -- References DB 11 (Documents) documents.id
                sort_order  SMALLINT     NOT NULL DEFAULT 0,
                description VARCHAR(255) NULL,
                latitude    NUMERIC(9,6) NULL,
                longitude   NUMERIC(9,6) NULL,
                is_boundary BOOLEAN      NOT NULL DEFAULT false,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at  TIMESTAMPTZ  NULL
            );

            COMMENT ON COLUMN property_map_images.is_boundary IS 'Exactly one live row per property — the boundary map shown publicly. Enforced by PropertyMapService.';
            COMMENT ON COLUMN property_map_images.latitude    IS 'Map reference point (WGS84) — display only. Auto-extracted from EXIF GPS on upload or set manually.';

            CREATE INDEX idx_property_map_images_property_id ON property_map_images (property_id);
            CREATE INDEX idx_property_map_images_sort_order  ON property_map_images (property_id, sort_order);

            CREATE TABLE property_map_markers (
                id           UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                map_image_id UUID          NOT NULL REFERENCES property_map_images (id) ON DELETE CASCADE,
                label        VARCHAR(100)  NOT NULL,
                marker_type  VARCHAR(20)   NOT NULL DEFAULT 'other'
                                 CHECK (marker_type IN ('amenity', 'game', 'stand', 'camera', 'access', 'hazard', 'water', 'other')),
                x_percent    NUMERIC(6,3)  NOT NULL CHECK (x_percent >= 0 AND x_percent <= 100),
                y_percent    NUMERIC(6,3)  NOT NULL CHECK (y_percent >= 0 AND y_percent <= 100),
                latitude     NUMERIC(9,6)  NULL,
                longitude    NUMERIC(9,6)  NULL,
                notes        VARCHAR(255)  NULL,
                created_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                deleted_at   TIMESTAMPTZ   NULL
            );

            COMMENT ON COLUMN property_map_markers.x_percent IS 'Marker position on the image, percent from the left edge (0-100). Image-anchored, not geographic.';
            COMMENT ON COLUMN property_map_markers.y_percent IS 'Marker position on the image, percent from the top edge (0-100).';

            CREATE INDEX idx_property_map_markers_map_image_id ON property_map_markers (map_image_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS property_map_markers CASCADE;
            DROP TABLE IF EXISTS property_map_images CASCADE;
        SQL);
    }
};
