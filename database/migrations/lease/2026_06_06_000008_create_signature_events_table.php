<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE signature_events (
                id                    UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id              UUID         NOT NULL REFERENCES leases (id),
                user_id               UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                provider              VARCHAR(50)  NOT NULL DEFAULT 'dropbox_sign',
                provider_signature_id VARCHAR(255) NULL,
                event_type            VARCHAR(20)  NOT NULL
                                          CHECK (event_type IN ('sent', 'viewed', 'signed', 'declined', 'completed')),
                occurred_at           TIMESTAMPTZ  NOT NULL,
                ip_address            INET         NULL,
                user_agent            TEXT         NULL,
                created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
                -- PERMANENT legal record: no updated_at, no deleted_at
                -- Model overrides delete()/forceDelete() to throw LogicException
            );

            CREATE INDEX idx_signature_events_lease_id   ON signature_events (lease_id);
            CREATE INDEX idx_signature_events_user_id    ON signature_events (user_id);
            CREATE INDEX idx_signature_events_event_type ON signature_events (event_type);
            CREATE INDEX idx_signature_events_occurred   ON signature_events (occurred_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS signature_events CASCADE;');
    }
};
