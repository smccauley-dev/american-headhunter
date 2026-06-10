<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // No deleted_at — superseded zones are replaced by ETL with new effective_date rows
        $conn->statement(<<<'SQL'
            CREATE TABLE cwd_management_zones (
                id             UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                state_code     CHAR(2)      NOT NULL,
                zone_name      VARCHAR(100) NOT NULL,
                zone_type      VARCHAR(20)  NOT NULL,
                boundary       GEOMETRY(MULTIPOLYGON, 4326) NOT NULL,
                effective_date DATE         NOT NULL,
                source_url     TEXT         NULL,
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_cwd_zones_type
                    CHECK (zone_type IN ('positive', 'surveillance', 'management'))
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_cwd_zones_state ON cwd_management_zones (state_code, zone_type)'
        );
        $conn->statement(
            'CREATE INDEX idx_cwd_zones_boundary_gist ON cwd_management_zones USING GIST (boundary)'
        );
        $conn->statement(<<<'SQL'
            CREATE TRIGGER trg_cwd_management_zones_updated_at
                BEFORE UPDATE ON cwd_management_zones
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at()
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS cwd_management_zones CASCADE');
    }
};
