<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Same-DB FK to trail_cameras is allowed (both in DB 5). document_id is a bare
        // cross-DB ref to DB 11; the image is not servable until virus scan marks it ready.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE trail_camera_photos (
                id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                camera_id        UUID         NOT NULL REFERENCES trail_cameras (id) ON DELETE CASCADE,
                document_id      UUID         NOT NULL,  -- References DB 11 (Documents) documents.id
                taken_at         TIMESTAMPTZ  NOT NULL,
                species_detected JSONB        NOT NULL DEFAULT '[]',
                    -- array of: {"species_code": "whitetail_deer", "confidence": 0.94, "count": 2}
                ai_processed_at  TIMESTAMPTZ  NULL,
                ai_confidence    NUMERIC(4,3) NULL CHECK (ai_confidence BETWEEN 0 AND 1),
                is_flagged       BOOLEAN      NOT NULL DEFAULT false,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at       TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_trail_camera_photos_camera_id   ON trail_camera_photos (camera_id);
            CREATE INDEX idx_trail_camera_photos_taken_at    ON trail_camera_photos (camera_id, taken_at DESC);
            CREATE INDEX idx_trail_camera_photos_flagged     ON trail_camera_photos (is_flagged) WHERE is_flagged = true;
            CREATE INDEX idx_trail_camera_photos_species_gin ON trail_camera_photos USING GIN (species_detected);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS trail_camera_photos CASCADE');
    }
};
