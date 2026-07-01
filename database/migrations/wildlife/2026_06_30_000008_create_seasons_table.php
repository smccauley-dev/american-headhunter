<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE seasons (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                state_code   CHAR(2)      NOT NULL,
                species_code VARCHAR(50)  NOT NULL,
                season_name  VARCHAR(100) NOT NULL,
                season_type  VARCHAR(20)  NOT NULL
                                 CHECK (season_type IN ('archery', 'rifle', 'muzzleloader', 'general', 'youth', 'special')),
                start_date   DATE         NOT NULL,
                end_date     DATE         NOT NULL,
                year         SMALLINT     NOT NULL,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_seasons_dates CHECK (end_date >= start_date)
            );

            CREATE UNIQUE INDEX uq_seasons_state_species_type_year
                ON seasons (state_code, species_code, season_type, year);
            CREATE INDEX idx_seasons_state_code   ON seasons (state_code);
            CREATE INDEX idx_seasons_species_code ON seasons (species_code);
            CREATE INDEX idx_seasons_year         ON seasons (year);
            CREATE INDEX idx_seasons_dates        ON seasons (start_date, end_date);

            CREATE TRIGGER trg_seasons_updated_at
                BEFORE UPDATE ON seasons
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS seasons CASCADE');
    }
};
