<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddOriginalStateCodeToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // original_state_code is the FIRST residence state ever recorded for a
        // profile. It is captured once from state_code and then immutable — the
        // single_state_hunt entitlement locks a hunter to this state even if they
        // later edit their home state. A BEFORE trigger enforces capture + write
        // protection at the DB level so every writer (service, controller, admin
        // panel) behaves identically.
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS original_state_code CHAR(2) NULL;

            COMMENT ON COLUMN user_profiles.original_state_code IS
                'Immutable first residence state ever recorded. Captured once from state_code and never overwritten — single_state_hunt locks a hunter to this state even after they change their home state.';

            -- Backfill existing profiles: their current state is their original.
            UPDATE user_profiles
               SET original_state_code = state_code
             WHERE original_state_code IS NULL
               AND state_code IS NOT NULL;

            CREATE OR REPLACE FUNCTION capture_original_state_code()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF TG_OP = 'UPDATE' AND OLD.original_state_code IS NOT NULL THEN
                    -- Once set, it is immutable: ignore any attempt to change it.
                    NEW.original_state_code := OLD.original_state_code;
                ELSIF NEW.original_state_code IS NULL AND NEW.state_code IS NOT NULL THEN
                    -- First time a residence state is known: lock it in.
                    NEW.original_state_code := NEW.state_code;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_user_profiles_capture_original_state ON user_profiles;
            CREATE TRIGGER trg_user_profiles_capture_original_state
                BEFORE INSERT OR UPDATE ON user_profiles
                FOR EACH ROW EXECUTE FUNCTION capture_original_state_code();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP TRIGGER IF EXISTS trg_user_profiles_capture_original_state ON user_profiles;
            DROP FUNCTION IF EXISTS capture_original_state_code();
            ALTER TABLE user_profiles DROP COLUMN IF EXISTS original_state_code;
        SQL);
    }
}
