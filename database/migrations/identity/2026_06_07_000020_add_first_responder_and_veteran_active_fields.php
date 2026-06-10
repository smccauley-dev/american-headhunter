<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE users
                ADD COLUMN is_first_responder BOOLEAN NOT NULL DEFAULT false;

            COMMENT ON COLUMN users.is_first_responder IS 'Set true after first responder credential verification';

            ALTER TABLE user_profiles
                ADD COLUMN veteran_is_active              BOOLEAN      NOT NULL DEFAULT false,
                ADD COLUMN first_responder_type           VARCHAR(30)  NULL
                    CHECK (first_responder_type IN (
                        'police','sheriff','fire','emt_paramedic',
                        'search_rescue','dispatcher','correctional'
                    ) OR first_responder_type IS NULL),
                ADD COLUMN first_responder_service_start  SMALLINT     NULL
                    CHECK (first_responder_service_start BETWEEN 1940 AND 2100 OR first_responder_service_start IS NULL),
                ADD COLUMN first_responder_service_end    SMALLINT     NULL
                    CHECK (first_responder_service_end BETWEEN 1940 AND 2100 OR first_responder_service_end IS NULL),
                ADD COLUMN first_responder_is_active      BOOLEAN      NOT NULL DEFAULT false,
                ADD COLUMN first_responder_last_rank      VARCHAR(100) NULL,
                ADD COLUMN first_responder_bio            TEXT         NULL;

            COMMENT ON COLUMN user_profiles.veteran_is_active             IS 'True if currently on active duty';
            COMMENT ON COLUMN user_profiles.first_responder_type          IS 'Category of first responder service';
            COMMENT ON COLUMN user_profiles.first_responder_service_start IS 'Year first responder service began';
            COMMENT ON COLUMN user_profiles.first_responder_service_end   IS 'Year first responder service ended';
            COMMENT ON COLUMN user_profiles.first_responder_is_active     IS 'True if currently active first responder';
            COMMENT ON COLUMN user_profiles.first_responder_last_rank     IS 'Last or current rank/title';
            COMMENT ON COLUMN user_profiles.first_responder_bio           IS 'Optional service narrative';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE users
                DROP COLUMN IF EXISTS is_first_responder;

            ALTER TABLE user_profiles
                DROP COLUMN IF EXISTS veteran_is_active,
                DROP COLUMN IF EXISTS first_responder_type,
                DROP COLUMN IF EXISTS first_responder_service_start,
                DROP COLUMN IF EXISTS first_responder_service_end,
                DROP COLUMN IF EXISTS first_responder_is_active,
                DROP COLUMN IF EXISTS first_responder_last_rank,
                DROP COLUMN IF EXISTS first_responder_bio;
        SQL);
    }
};
