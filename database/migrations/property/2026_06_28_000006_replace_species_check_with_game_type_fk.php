<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    /**
     * Replace the static species_code CHECK list with a foreign key to the new
     * game_types registry, so game types become admin-managed. RESTRICT keeps a
     * type that is in use from being deleted (admins deactivate instead).
     */
    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_species
                DROP CONSTRAINT IF EXISTS property_species_species_code_check;

            ALTER TABLE property_species
                ADD CONSTRAINT fk_property_species_game_type
                    FOREIGN KEY (species_code) REFERENCES game_types (code)
                    ON UPDATE CASCADE ON DELETE RESTRICT;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_species
                DROP CONSTRAINT IF EXISTS fk_property_species_game_type;

            ALTER TABLE property_species
                ADD CONSTRAINT property_species_species_code_check
                    CHECK (species_code IN (
                        'whitetail_deer', 'mule_deer', 'turkey', 'waterfowl', 'dove',
                        'hog', 'elk', 'bear', 'antelope', 'pheasant', 'quail',
                        'rabbit', 'squirrel', 'coyote', 'other'
                    ));
        SQL);
    }
};
