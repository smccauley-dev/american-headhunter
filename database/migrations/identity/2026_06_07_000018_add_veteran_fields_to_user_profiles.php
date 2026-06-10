<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN veteran_branch        VARCHAR(30)  NULL
                    CHECK (veteran_branch IN (
                        'army','navy','air_force','marine_corps',
                        'coast_guard','space_force','national_guard','reserves'
                    ) OR veteran_branch IS NULL),
                ADD COLUMN veteran_service_years SMALLINT     NULL
                    CHECK (veteran_service_years BETWEEN 0 AND 60 OR veteran_service_years IS NULL),
                ADD COLUMN veteran_last_rank     VARCHAR(100) NULL,
                ADD COLUMN veteran_bio           TEXT         NULL;

            COMMENT ON COLUMN user_profiles.veteran_branch        IS 'Branch of military service';
            COMMENT ON COLUMN user_profiles.veteran_service_years IS 'Total years served';
            COMMENT ON COLUMN user_profiles.veteran_last_rank     IS 'Highest or last held military rank';
            COMMENT ON COLUMN user_profiles.veteran_bio           IS 'Optional service narrative';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS veteran_branch,
                DROP COLUMN IF EXISTS veteran_service_years,
                DROP COLUMN IF EXISTS veteran_last_rank,
                DROP COLUMN IF EXISTS veteran_bio;
        SQL);
    }
};
