<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE personal_access_tokens (
                id              BIGSERIAL    PRIMARY KEY,
                tokenable_type  VARCHAR(255) NOT NULL,
                tokenable_id    VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                token           VARCHAR(64)  NOT NULL,
                abilities       TEXT         NULL,
                last_used_at    TIMESTAMPTZ  NULL,
                expires_at      TIMESTAMPTZ  NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_personal_access_tokens_token
                ON personal_access_tokens (token);
            CREATE INDEX idx_personal_access_tokens_tokenable
                ON personal_access_tokens (tokenable_type, tokenable_id);
            CREATE INDEX idx_personal_access_tokens_expires_at
                ON personal_access_tokens (expires_at);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON personal_access_tokens
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS personal_access_tokens CASCADE'
        );
    }
};
