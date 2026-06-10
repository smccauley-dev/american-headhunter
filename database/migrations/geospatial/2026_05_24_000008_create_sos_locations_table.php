<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // Permanent life-safety record — no updated_at, no deleted_at, never delete rows
        $conn->statement(<<<'SQL'
            CREATE TABLE sos_locations (
                id                UUID     NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                sos_event_log_id  UUID     NOT NULL,  -- References DB 7 (Communications) sos_event_log.id
                location          GEOMETRY(POINT, 4326) NOT NULL,
                accuracy_meters   SMALLINT NULL,
                recorded_at       TIMESTAMPTZ NOT NULL,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_sos_locations_sos_event_log_id ON sos_locations (sos_event_log_id)'
        );
        $conn->statement(
            'CREATE INDEX idx_sos_locations_location_gist ON sos_locations USING GIST (location)'
        );
        $conn->statement(
            'CREATE INDEX idx_sos_locations_recorded_at ON sos_locations (recorded_at DESC)'
        );
    }

    public function down(): void
    {
        // SOS locations are permanent life-safety records and should not be dropped in production.
        // This down() exists only for development environments.
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS sos_locations CASCADE');
    }
};
