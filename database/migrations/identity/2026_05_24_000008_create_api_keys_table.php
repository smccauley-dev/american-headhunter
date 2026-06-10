<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE api_keys (
                id           UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id      UUID         NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                name         VARCHAR(100) NOT NULL,
                key_hash     TEXT         NOT NULL,
                key_prefix   CHAR(8)      NOT NULL,
                scopes       JSONB        NOT NULL DEFAULT '[]',
                last_used_at TIMESTAMPTZ  NULL,
                expires_at   TIMESTAMPTZ  NULL,
                revoked_at   TIMESTAMPTZ  NULL,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at   TIMESTAMPTZ  NULL
            );

            CREATE UNIQUE INDEX uq_api_keys_key_hash   ON api_keys (key_hash);
            CREATE        INDEX idx_api_keys_user_id    ON api_keys (user_id);
            CREATE        INDEX idx_api_keys_deleted_at ON api_keys (deleted_at) WHERE deleted_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS api_keys CASCADE'
        );
    }
};
