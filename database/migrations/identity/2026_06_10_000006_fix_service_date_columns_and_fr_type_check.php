<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            -- Drop SMALLINT service-year columns and re-add as DATE
            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS veteran_service_start,
                DROP COLUMN IF EXISTS veteran_service_end,
                DROP COLUMN IF EXISTS first_responder_service_start,
                DROP COLUMN IF EXISTS first_responder_service_end;

            ALTER TABLE user_profiles
                ADD COLUMN veteran_service_start        DATE NULL,
                ADD COLUMN veteran_service_end          DATE NULL,
                ADD COLUMN first_responder_service_start DATE NULL,
                ADD COLUMN first_responder_service_end   DATE NULL;

            COMMENT ON COLUMN user_profiles.veteran_service_start         IS 'Date military service began';
            COMMENT ON COLUMN user_profiles.veteran_service_end           IS 'Date military service ended';
            COMMENT ON COLUMN user_profiles.first_responder_service_start IS 'Date first responder service began';
            COMMENT ON COLUMN user_profiles.first_responder_service_end   IS 'Date first responder service ended';

            -- Fix first_responder_type CHECK to match application constants
            ALTER TABLE user_profiles
                DROP CONSTRAINT IF EXISTS user_profiles_first_responder_type_check;

            ALTER TABLE user_profiles
                ADD CONSTRAINT user_profiles_first_responder_type_check
                CHECK (first_responder_type IN (
                    'law_enforcement','fire','emt','search_rescue',
                    'corrections','dispatch','other'
                ) OR first_responder_type IS NULL);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS veteran_service_start,
                DROP COLUMN IF EXISTS veteran_service_end,
                DROP COLUMN IF EXISTS first_responder_service_start,
                DROP COLUMN IF EXISTS first_responder_service_end;

            ALTER TABLE user_profiles
                ADD COLUMN veteran_service_start         SMALLINT NULL
                    CHECK (veteran_service_start BETWEEN 1940 AND 2100 OR veteran_service_start IS NULL),
                ADD COLUMN veteran_service_end           SMALLINT NULL
                    CHECK (veteran_service_end BETWEEN 1940 AND 2100 OR veteran_service_end IS NULL),
                ADD COLUMN first_responder_service_start SMALLINT NULL
                    CHECK (first_responder_service_start BETWEEN 1940 AND 2100 OR first_responder_service_start IS NULL),
                ADD COLUMN first_responder_service_end   SMALLINT NULL
                    CHECK (first_responder_service_end BETWEEN 1940 AND 2100 OR first_responder_service_end IS NULL);

            ALTER TABLE user_profiles
                DROP CONSTRAINT IF EXISTS user_profiles_first_responder_type_check;

            ALTER TABLE user_profiles
                ADD CONSTRAINT user_profiles_first_responder_type_check
                CHECK (first_responder_type IN (
                    'police','sheriff','fire','emt_paramedic',
                    'search_rescue','dispatcher','correctional'
                ) OR first_responder_type IS NULL);
        SQL);
    }
};
