<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'audit';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE audit_log (
                id               UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                event_type       VARCHAR(50)  NOT NULL,
                source_database  VARCHAR(50)  NOT NULL,
                table_name       VARCHAR(100) NOT NULL,
                record_id        VARCHAR(255) NOT NULL,
                user_id          UUID         NULL,
                session_id       VARCHAR(255) NULL,
                action_summary   TEXT         NULL,
                changed_fields   JSONB        NULL,
                old_values       JSONB        NULL,
                new_values       JSONB        NULL,
                ip_address       INET         NULL,
                user_agent       TEXT         NULL,
                occurred_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_audit_log_occurred_at      ON audit_log (occurred_at DESC);
            CREATE INDEX idx_audit_log_user_id          ON audit_log (user_id) WHERE user_id IS NOT NULL;
            CREATE INDEX idx_audit_log_event_type       ON audit_log (event_type);
            CREATE INDEX idx_audit_log_table_name       ON audit_log (table_name);
            CREATE INDEX idx_audit_log_source_database  ON audit_log (source_database);

            -- Immutability: block UPDATE and DELETE at the database level
            CREATE RULE audit_log_no_update AS ON UPDATE TO audit_log DO INSTEAD NOTHING;
            CREATE RULE audit_log_no_delete AS ON DELETE TO audit_log DO INSTEAD NOTHING;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS audit_log CASCADE;'
        );
    }
};
