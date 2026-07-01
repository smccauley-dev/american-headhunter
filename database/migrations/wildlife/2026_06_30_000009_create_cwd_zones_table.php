<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Regulatory metadata only. Zone polygons live in DB 13 (cwd_management_zones),
        // linked by GeospatialService. cwd_acknowledgments references this table's id.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE cwd_zones (
                id             UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                state_code     CHAR(2)      NOT NULL,
                zone_name      VARCHAR(100) NOT NULL,
                zone_type      VARCHAR(15)  NOT NULL
                                   CHECK (zone_type IN ('positive', 'surveillance', 'management')),
                regulations    TEXT         NULL,
                effective_date DATE         NOT NULL,
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_cwd_zones_state_code ON cwd_zones (state_code);
            CREATE INDEX idx_cwd_zones_zone_type  ON cwd_zones (zone_type);

            CREATE TRIGGER trg_cwd_zones_updated_at
                BEFORE UPDATE ON cwd_zones
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS cwd_zones CASCADE');
    }
};
