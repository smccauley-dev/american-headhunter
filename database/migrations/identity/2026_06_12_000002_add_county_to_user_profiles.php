<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddCountyToUserProfiles extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // Encrypted address field — base64 pgp_sym_encrypt (identity key), handled
        // transparently by the UserProfile HasEncryptedFields trait.
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles
                ADD COLUMN IF NOT EXISTS county TEXT NULL;

            COMMENT ON COLUMN user_profiles.county IS
                'encrypted (pgp_sym, identity key) — county / parish / district';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE user_profiles DROP COLUMN IF EXISTS county;
        SQL);
    }
}
