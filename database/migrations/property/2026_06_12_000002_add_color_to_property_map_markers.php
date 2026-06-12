<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_markers
                ADD COLUMN color VARCHAR(7) NULL
                    CHECK (color IS NULL OR color ~ '^#[0-9a-fA-F]{6}$');

            COMMENT ON COLUMN property_map_markers.color IS 'Optional hex override for the pin color. NULL falls back to the marker type default.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_map_markers DROP COLUMN IF EXISTS color;
        SQL);
    }
};
