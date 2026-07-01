<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE population_surveys (
                id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id      UUID         NOT NULL,  -- References DB 2 (Property) properties.id
                species_code     VARCHAR(50)  NOT NULL,
                survey_year      SMALLINT     NOT NULL,
                method           VARCHAR(50)  NOT NULL,  -- 'game_camera', 'aerial', 'observation', 'track_count', etc.
                estimated_count  SMALLINT     NULL,
                buck_doe_ratio   NUMERIC(4,2) NULL,
                notes            TEXT         NULL,
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_population_surveys_property_species_year
                ON population_surveys (property_id, species_code, survey_year);
            CREATE INDEX idx_population_surveys_property_id ON population_surveys (property_id);
            CREATE INDEX idx_population_surveys_year        ON population_surveys (survey_year);

            CREATE TRIGGER trg_population_surveys_updated_at
                BEFORE UPDATE ON population_surveys
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS population_surveys CASCADE');
    }
};
