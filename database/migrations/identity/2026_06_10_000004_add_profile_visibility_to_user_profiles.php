<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddProfileVisibilityToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS profile_visibility JSONB NOT NULL
                    DEFAULT '{"about":"public","contact":"private","social":"private"}';

            COMMENT ON COLUMN user_profiles.profile_visibility IS
                'Per-section visibility settings. Keys: about, contact, social. Values: public|private.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles DROP COLUMN IF EXISTS profile_visibility;
        SQL);
    }
}
