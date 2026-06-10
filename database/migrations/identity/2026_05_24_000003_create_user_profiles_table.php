<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE user_profiles (
                id                          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                     UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                first_name                  VARCHAR(100) NULL,
                last_name                   VARCHAR(100) NULL,
                display_name                VARCHAR(100) NULL,
                avatar_document_id          UUID         NULL,
                bio                         TEXT         NULL,
                state_code                  CHAR(2)      NULL,
                zip_code                    VARCHAR(10)  NULL,
                date_of_birth               DATE         NULL,
                gender                      VARCHAR(20)  NULL
                                                CHECK (gender IN ('male', 'female', 'non_binary', 'prefer_not_to_say') OR gender IS NULL),
                notification_preferences    JSONB        NOT NULL DEFAULT '{}',
                hunting_profile             JSONB        NOT NULL DEFAULT '{}',
                created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_user_profiles_user_id ON user_profiles (user_id);
            CREATE        INDEX idx_user_profiles_state  ON user_profiles (state_code);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON user_profiles
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS user_profiles CASCADE'
        );
    }
};
