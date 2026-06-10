<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE mfa_configurations (
                id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                 UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                method                  VARCHAR(10) NOT NULL
                                            CHECK (method IN ('totp', 'sms', 'email')),
                is_enabled              BOOLEAN     NOT NULL DEFAULT false,
                secret_encrypted        TEXT        NULL,
                backup_codes_encrypted  TEXT        NULL,
                verified_at             TIMESTAMPTZ NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_mfa_configurations_user_method
                ON mfa_configurations (user_id, method);
            CREATE INDEX idx_mfa_configurations_user_id
                ON mfa_configurations (user_id);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON mfa_configurations
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE mfa_challenges (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                method      VARCHAR(10) NOT NULL CHECK (method IN ('totp', 'sms', 'email')),
                code_hash   TEXT        NOT NULL,
                expires_at  TIMESTAMPTZ NOT NULL,
                used_at     TIMESTAMPTZ NULL,
                ip_address  INET        NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_mfa_challenges_user_id    ON mfa_challenges (user_id);
            CREATE INDEX idx_mfa_challenges_expires_at ON mfa_challenges (expires_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS mfa_challenges CASCADE;
            DROP TABLE IF EXISTS mfa_configurations CASCADE;
        SQL);
    }
};
