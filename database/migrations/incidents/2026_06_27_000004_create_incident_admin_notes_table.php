<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — incident_admin_notes (DB 10), the admin-only investigation log.
 *
 * A timestamped line-item log of internal notes the safety team takes while working
 * an incident. Each note is its own append-only row (author + when), newest first in
 * the admin UI. These are NEVER shown to the reporter.
 *
 * Admin-only by construction, not just by convention: unlike incident_reports — whose
 * RLS SELECT policy lets the reporter read their own row — this table's SELECT policy
 * is gated to staff/super_admin ONLY. The reporter shares the incident row but can
 * never read its investigation notes even at the database level. There is no write
 * policy, so the inherited ah_runtime DML grant is inert for writes (SEC-045): notes
 * are authored only by ah_system (the Filament admin panel, BYPASSRLS).
 *
 * Append-only: the table carries created_at but no updated_at/deleted_at and the UI
 * exposes no edit/delete — an investigation note, once taken, stands as a record.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE incident_admin_notes (
                id                 UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                incident_report_id UUID NOT NULL REFERENCES incident_reports (id) ON DELETE CASCADE,
                author_user_id     UUID NOT NULL,           -- References DB 1 (Identity) users.id
                note               TEXT NOT NULL,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE INDEX idx_incident_admin_notes_report
                ON incident_admin_notes (incident_report_id, created_at DESC);

            ALTER TABLE incident_admin_notes ENABLE ROW LEVEL SECURITY;

            -- Staff/super_admin only — the reporter must never read investigation notes.
            -- No INSERT/UPDATE/DELETE policy: writes are system-authored (ah_system).
            CREATE POLICY incident_admin_notes_staff_only ON incident_admin_notes
                FOR SELECT TO ah_runtime
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS incident_admin_notes CASCADE;');
    }
};
