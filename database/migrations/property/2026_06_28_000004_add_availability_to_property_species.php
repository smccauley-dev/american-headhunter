<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        // Each game type is either huntable only in a regulated season (deer,
        // turkey, …) or year-round (hog, coyote — no closed season in most
        // states). Landowners set this per species; existing rows are backfilled
        // from the common-case default.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_species
                ADD COLUMN availability VARCHAR(20) NOT NULL DEFAULT 'seasonal'
                    CHECK (availability IN ('seasonal', 'year_round'));

            UPDATE property_species SET availability = 'year_round'
                WHERE species_code IN ('hog', 'coyote');
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('ALTER TABLE property_species DROP COLUMN IF EXISTS availability');
    }
};
