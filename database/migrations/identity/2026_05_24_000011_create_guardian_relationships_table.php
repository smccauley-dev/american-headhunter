<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE guardian_relationships (
                id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                minor_user_id       UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                guardian_user_id    UUID        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                consent_granted_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                consent_expires_at  TIMESTAMPTZ NULL,
                revoked_at          TIMESTAMPTZ NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_guardian_relationships_pair UNIQUE (minor_user_id, guardian_user_id)
            );

            CREATE INDEX idx_guardian_relationships_minor    ON guardian_relationships (minor_user_id);
            CREATE INDEX idx_guardian_relationships_guardian ON guardian_relationships (guardian_user_id);

            CREATE TRIGGER set_updated_at
                BEFORE UPDATE ON guardian_relationships
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP TABLE IF EXISTS guardian_relationships CASCADE'
        );
    }
};
