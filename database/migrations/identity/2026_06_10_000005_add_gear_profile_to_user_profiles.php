<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddGearProfileToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS gear_profile JSONB NOT NULL DEFAULT '{"items":[]}';

            COMMENT ON COLUMN user_profiles.gear_profile IS
                'Hunter gear list. Structure: {"items":[{"id","category","brand","model","notes"}]}';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles DROP COLUMN IF EXISTS gear_profile;
        SQL);
    }
}
