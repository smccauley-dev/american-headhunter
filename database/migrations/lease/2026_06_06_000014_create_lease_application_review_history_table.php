<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_application_review_history (
                id                  UUID        NOT NULL DEFAULT gen_random_uuid(),
                application_id      UUID        NOT NULL REFERENCES lease_applications(id),
                decided_by_user_id  UUID        NOT NULL,   -- References DB 1 users.id
                from_status         VARCHAR(20) NULL,       -- NULL on first decision
                to_status           VARCHAR(20) NOT NULL
                                        CHECK (to_status IN ('approved', 'rejected')),
                reason              TEXT        NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

                CONSTRAINT pk_lease_application_review_history PRIMARY KEY (id)
            );

            CREATE INDEX idx_lease_app_review_history_app_id
                ON lease_application_review_history (application_id, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS lease_application_review_history;'
        );
    }
};
