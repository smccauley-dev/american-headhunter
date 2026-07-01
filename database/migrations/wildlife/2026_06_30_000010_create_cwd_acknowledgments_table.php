<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Legal compliance record: the hunter acknowledged CWD zone requirements
        // (e.g. mandatory sample submission) for a harvest in a positive/surveillance
        // zone. Append-only by convention — no updated_at, no soft delete. harvest_log_id
        // and cwd_zone_id are same-DB FKs (both DB 5); the write is also audited via
        // AuditService (audit_event_id is the correlation ref to DB 9).
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE cwd_acknowledgments (
                id              UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id         UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                harvest_log_id  UUID        NOT NULL REFERENCES harvest_logs (id) ON DELETE CASCADE,
                cwd_zone_id     UUID        NOT NULL REFERENCES cwd_zones (id),
                acknowledged_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                audit_event_id  UUID        NULL,  -- References DB 9 (Audit) audit_log.id — correlation ref
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_cwd_acknowledgments_user_id     ON cwd_acknowledgments (user_id);
            CREATE INDEX idx_cwd_acknowledgments_harvest_log ON cwd_acknowledgments (harvest_log_id);
            CREATE INDEX idx_cwd_acknowledgments_zone_id     ON cwd_acknowledgments (cwd_zone_id);
            CREATE UNIQUE INDEX uq_cwd_acknowledgments_harvest_zone
                ON cwd_acknowledgments (harvest_log_id, cwd_zone_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS cwd_acknowledgments CASCADE');
    }
};
