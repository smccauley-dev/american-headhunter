<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddSocialLinksToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS social_links JSONB NOT NULL DEFAULT '{}';

            COMMENT ON COLUMN user_profiles.social_links IS
                'Social media profile links keyed by platform slug (instagram, facebook, x, discord, etc.)';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles DROP COLUMN IF EXISTS social_links;
        SQL);
    }
}
