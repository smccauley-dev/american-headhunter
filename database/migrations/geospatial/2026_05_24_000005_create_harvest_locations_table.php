<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // Immutable — no updated_at, no deleted_at
        $conn->statement(<<<'SQL'
            CREATE TABLE harvest_locations (
                id              UUID     NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                harvest_log_id  UUID     NOT NULL,  -- References DB 5 (Wildlife) harvest_logs.id
                location        GEOMETRY(POINT, 4326) NOT NULL,
                accuracy_meters SMALLINT NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_harvest_locations_harvest_log_id ON harvest_locations (harvest_log_id)'
        );
        $conn->statement(
            'CREATE INDEX idx_harvest_locations_location_gist ON harvest_locations USING GIST (location)'
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS harvest_locations CASCADE');
    }
};
