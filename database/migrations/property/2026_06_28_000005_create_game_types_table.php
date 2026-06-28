<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE game_types (
                id                   UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                code                 VARCHAR(50) NOT NULL UNIQUE,
                label                VARCHAR(60) NOT NULL,
                icon_svg             TEXT,
                icon_viewbox         VARCHAR(40) NOT NULL DEFAULT '0 0 512 512',
                default_availability VARCHAR(20) NOT NULL DEFAULT 'seasonal'
                                         CHECK (default_availability IN ('seasonal', 'year_round')),
                sort_order           INTEGER     NOT NULL DEFAULT 0,
                is_active            BOOLEAN     NOT NULL DEFAULT true,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_game_types_active_sort ON game_types (is_active, sort_order);

            CREATE TRIGGER trg_game_types_updated_at
                BEFORE UPDATE ON game_types
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);

        // Seed the registry with the originally-hardcoded species list + their
        // game-icons.net glyphs. Insert via the query builder so the long SVG
        // strings are parameterised (no manual quoting). Existing databases must
        // have these rows present before the FK migration runs.
        $rows = require database_path('data/game_types_seed.php');

        DB::connection($this->connection)->table('game_types')->insert($rows);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS game_types CASCADE');
    }
};
