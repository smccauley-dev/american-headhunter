<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE oauth_connections (
                id                       UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id                  UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                provider                 VARCHAR(20)  NOT NULL
                                             CHECK (provider IN ('google', 'apple', 'facebook', 'discord')),
                provider_user_id         VARCHAR(255) NOT NULL,
                provider_email           VARCHAR(255) NULL,
                access_token_encrypted   TEXT         NULL,
                refresh_token_encrypted  TEXT         NULL,
                token_expires_at         TIMESTAMPTZ  NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_oauth_connections_provider_user
                ON oauth_connections (provider, provider_user_id);
            CREATE INDEX idx_oauth_connections_user_id ON oauth_connections (user_id);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON oauth_connections
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS oauth_connections CASCADE'
        );
    }
};
