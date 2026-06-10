<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id     UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                token_hash  TEXT        NOT NULL,
                expires_at  TIMESTAMPTZ NOT NULL,
                used_at     TIMESTAMPTZ NULL,
                ip_address  INET        NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_password_reset_tokens_user_id    ON password_reset_tokens (user_id);
            CREATE INDEX idx_password_reset_tokens_expires_at ON password_reset_tokens (expires_at);

            CREATE TABLE email_verification_tokens (
                id          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id     UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                email       VARCHAR(255) NOT NULL,
                token_hash  TEXT         NOT NULL,
                expires_at  TIMESTAMPTZ  NOT NULL,
                verified_at TIMESTAMPTZ  NULL,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_email_verification_tokens_user_id ON email_verification_tokens (user_id);
            CREATE INDEX idx_email_verification_tokens_email   ON email_verification_tokens (email);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS email_verification_tokens CASCADE;
            DROP TABLE IF EXISTS password_reset_tokens CASCADE;
        SQL);
    }
};
