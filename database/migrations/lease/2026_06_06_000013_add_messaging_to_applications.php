<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- Add admin/landowner notes to applications
            ALTER TABLE lease_applications
                ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL;

            -- Application-scoped message thread
            CREATE TABLE lease_application_messages (
                id              UUID        NOT NULL DEFAULT gen_random_uuid(),
                application_id  UUID        NOT NULL REFERENCES lease_applications(id),
                sender_user_id  UUID        NOT NULL,   -- References DB 1 users.id
                sender_role     VARCHAR(20) NOT NULL
                                    CHECK (sender_role IN ('admin', 'landowner', 'applicant')),
                message         TEXT        NOT NULL,
                is_read         BOOLEAN     NOT NULL DEFAULT FALSE,
                read_at         TIMESTAMPTZ NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT pk_lease_application_messages PRIMARY KEY (id)
            );

            CREATE INDEX idx_lease_application_messages_app_id
                ON lease_application_messages (application_id, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS lease_application_messages;
            ALTER TABLE lease_applications DROP COLUMN IF EXISTS admin_notes;
        SQL);
    }
};
