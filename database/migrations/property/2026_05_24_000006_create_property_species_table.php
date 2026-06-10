<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_species (
                id           UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id  UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                species_code VARCHAR(50) NOT NULL
                                 CHECK (species_code IN (
                                     'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                                     'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                                     'rabbit', 'squirrel', 'coyote', 'other'
                                 )),
                is_primary   BOOLEAN     NOT NULL DEFAULT false,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT uq_property_species_property_species UNIQUE (property_id, species_code)
            );

            CREATE INDEX idx_property_species_property_id ON property_species (property_id);
            CREATE INDEX idx_property_species_code        ON property_species (species_code);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_species CASCADE');
    }
};
