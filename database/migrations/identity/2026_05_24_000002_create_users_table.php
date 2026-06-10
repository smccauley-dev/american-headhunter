<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE users (
                id                      UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                email                   VARCHAR(255) NOT NULL,
                email_verified_at       TIMESTAMPTZ NULL,
                phone                   VARCHAR(20)  NULL,
                phone_verified_at       TIMESTAMPTZ NULL,
                password_hash           TEXT        NOT NULL,
                status                  VARCHAR(30) NOT NULL DEFAULT 'pending_verification'
                                            CHECK (status IN ('active', 'suspended', 'banned', 'pending_verification')),
                account_type            VARCHAR(20) NOT NULL
                                            CHECK (account_type IN ('hunter', 'landowner', 'club', 'outfitter', 'consultant', 'seller', 'staff')),
                trust_score             SMALLINT    NOT NULL DEFAULT 50
                                            CHECK (trust_score BETWEEN 0 AND 100),
                is_veteran              BOOLEAN     NOT NULL DEFAULT false,
                discord_user_id         VARCHAR(30)  NULL,
                failed_login_attempts   SMALLINT    NOT NULL DEFAULT 0,
                locked_until            TIMESTAMPTZ NULL,
                last_login_at           TIMESTAMPTZ NULL,
                last_login_ip           INET        NULL,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ NULL
            );

            CREATE UNIQUE INDEX uq_users_email
                ON users (LOWER(email)) WHERE deleted_at IS NULL;
            CREATE UNIQUE INDEX uq_users_discord_user_id
                ON users (discord_user_id) WHERE discord_user_id IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX idx_users_status       ON users (status);
            CREATE INDEX idx_users_account_type ON users (account_type);
            CREATE INDEX idx_users_deleted_at   ON users (deleted_at) WHERE deleted_at IS NOT NULL;

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS users CASCADE'
        );
    }
};
