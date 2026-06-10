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
                DROP COLUMN IF EXISTS veteran_service_years,
                ADD COLUMN veteran_service_start SMALLINT NULL
                    CHECK (veteran_service_start BETWEEN 1940 AND 2100 OR veteran_service_start IS NULL),
                ADD COLUMN veteran_service_end SMALLINT NULL
                    CHECK (veteran_service_end BETWEEN 1940 AND 2100 OR veteran_service_end IS NULL);

            COMMENT ON COLUMN user_profiles.veteran_service_start IS 'Year military service began';
            COMMENT ON COLUMN user_profiles.veteran_service_end   IS 'Year military service ended';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS veteran_service_start,
                DROP COLUMN IF EXISTS veteran_service_end,
                ADD COLUMN veteran_service_years SMALLINT NULL
                    CHECK (veteran_service_years BETWEEN 0 AND 60 OR veteran_service_years IS NULL);
        SQL);
    }
};
