<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DB 7 — in-app notification center (the member "bell" / unread inbox).
 *
 * Append-only delivery record, one row per notification (the documented schema
 * also tracks email/sms/push deliveries; this build seeds the table for the
 * in_app channel, the others slot in unchanged). No updated_at / deleted_at —
 * a notification is created, optionally read (read_at stamped), and pruned by
 * PurgeOldNotificationsJob after the retention window. Never updated otherwise.
 *
 * RLS shape: rows are SYSTEM-AUTHORED. NotificationService writes them while
 * running as ah_system (queue jobs, or member-write routes wrapped in db.system
 * such as the early-termination decision) — there is NO INSERT policy, so an
 * ah_runtime request can never forge a notification for itself or anyone else.
 * A logged-in member may READ their own rows and may UPDATE only to mark their
 * own rows read; the UPDATE policy's WITH CHECK pins user_id so a member can
 * never reassign a row to another user. Staff/super_admin read all.
 */
return new class extends Migration
{
    protected $connection = 'communications';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE notifications (
                id              UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                user_id         UUID         NOT NULL,  -- References DB 1 (Identity) users.id
                type            VARCHAR(100) NOT NULL,
                    -- e.g. 'lease.early_termination_approved', 'message.received'
                channel         VARCHAR(10)  NOT NULL DEFAULT 'in_app'
                                    CHECK (channel IN ('in_app', 'email', 'sms', 'push')),
                title           VARCHAR(255) NOT NULL,
                body            TEXT         NOT NULL,
                action_url      VARCHAR(255) NULL,   -- relative click-through (e.g. /member/leases/{id})
                data            JSONB        NOT NULL DEFAULT '{}',  -- context ids only; never PII or payment details
                read_at         TIMESTAMPTZ  NULL,   -- in_app only
                sent_at         TIMESTAMPTZ  NULL,
                failed_at       TIMESTAMPTZ  NULL,
                failure_reason  VARCHAR(255) NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_notifications_user_id    ON notifications (user_id);
            CREATE INDEX idx_notifications_channel    ON notifications (channel);
            CREATE INDEX idx_notifications_type       ON notifications (type);
            CREATE INDEX idx_notifications_created_at ON notifications (user_id, created_at DESC);
            CREATE INDEX idx_notifications_unread     ON notifications (user_id, read_at) WHERE read_at IS NULL;
            CREATE INDEX idx_notifications_data_gin   ON notifications USING GIN (data);

            ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;

            -- Read your own; staff/super_admin read all.
            CREATE POLICY notifications_owner_read ON notifications
                FOR SELECT TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            -- Mark your own read. WITH CHECK pins user_id so the row can never be
            -- reassigned to another user. The controller only ever stamps read_at.
            CREATE POLICY notifications_owner_mark_read ON notifications
                FOR UPDATE TO ah_runtime
                USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID)
                WITH CHECK (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            -- No INSERT/DELETE policy by design — writes and pruning are
            -- system-authored (ah_system, BYPASSRLS).
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS notifications CASCADE;');
    }
};
