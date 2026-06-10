<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        $conn->statement(<<<'SQL'
            CREATE TABLE food_plots (
                id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id     UUID         NOT NULL,  -- References DB 2 (Property) properties.id
                name            VARCHAR(100) NOT NULL,
                boundary        GEOMETRY(POLYGON, 4326) NOT NULL,
                area_acres      NUMERIC(8,4) GENERATED ALWAYS AS (
                                    ST_Area(ST_Transform(boundary, 5070)) / 4046.856422
                                ) STORED,
                species_planted JSONB        NOT NULL DEFAULT '[]',
                planted_date    DATE         NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at      TIMESTAMPTZ  NULL
            )
        SQL);

        $conn->statement(
            'CREATE INDEX idx_food_plots_property_id ON food_plots (property_id) WHERE deleted_at IS NULL'
        );
        $conn->statement(
            'CREATE INDEX idx_food_plots_boundary_gist ON food_plots USING GIST (boundary) WHERE deleted_at IS NULL'
        );
        $conn->statement(<<<'SQL'
            CREATE TRIGGER trg_food_plots_updated_at
                BEFORE UPDATE ON food_plots
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at()
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS food_plots CASCADE');
    }
};
