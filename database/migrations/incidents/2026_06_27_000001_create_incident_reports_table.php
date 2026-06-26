<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — incident_reports (DB 10), the safety-incident intake.
 *
 * A member (lessee or landowner) files a safety incident tied to a property —
 * hunting accident, trespassing, property damage, wildlife encounter, medical, or
 * other — optionally with photo evidence. The safety team triages it through an
 * open → investigating → resolved → closed workflow in the Filament admin panel.
 *
 * System-authored, runtime-read-only (SEC-045): RLS is enabled with a single FOR
 * SELECT policy TO ah_runtime (the reporter + staff) and NO write policy, so the
 * table-level DML grant ah_runtime inherits is inert for writes — every
 * INSERT/UPDATE default-denies. Members file incidents only via the db.system route
 * (ah_system, BYPASSRLS); the Filament admin panel triages (also ah_system). A
 * reporter can read their own report but can never forge or alter one.
 *
 * Adds two things beyond the documented schema: evidence_document_ids (DB 11 photo
 * proof, a bare UUID-array ref assembled in the service layer) and soft deletes
 * (the doc's partial indexes already assume deleted_at IS NULL).
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS "pgcrypto";
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

            CREATE OR REPLACE FUNCTION trigger_set_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TABLE incident_reports (
                id                      UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id             UUID NOT NULL,           -- References DB 2 (Property) properties.id
                lease_id                UUID,                    -- References DB 3 (Lease) leases.id — NULL if no active lease
                reporter_user_id        UUID NOT NULL,           -- References DB 1 (Identity) users.id
                incident_type           VARCHAR(30) NOT NULL,
                severity                VARCHAR(20) NOT NULL,
                status                  VARCHAR(20) NOT NULL DEFAULT 'open',
                occurred_at             TIMESTAMPTZ NOT NULL,
                location_description    TEXT,
                description             TEXT NOT NULL,
                injuries_reported       BOOLEAN NOT NULL DEFAULT false,
                authorities_notified    BOOLEAN NOT NULL DEFAULT false,
                authority_report_number VARCHAR(100),
                evidence_document_ids   JSONB NOT NULL DEFAULT '[]',    -- Array of DB 11 documents.id UUIDs (photo proof)
                resolved_at             TIMESTAMPTZ,
                resolution_notes        TEXT,
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at              TIMESTAMPTZ,

                CONSTRAINT chk_incident_reports_type
                    CHECK (incident_type IN (
                        'hunting_accident', 'trespassing', 'property_damage',
                        'wildlife_encounter', 'medical', 'other'
                    )),
                CONSTRAINT chk_incident_reports_severity
                    CHECK (severity IN ('minor', 'moderate', 'serious', 'critical')),
                CONSTRAINT chk_incident_reports_status
                    CHECK (status IN ('open', 'investigating', 'resolved', 'closed'))
            );

            CREATE INDEX idx_incident_reports_property_id ON incident_reports (property_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_incident_reports_lease_id ON incident_reports (lease_id)
                WHERE lease_id IS NOT NULL AND deleted_at IS NULL;
            CREATE INDEX idx_incident_reports_reporter ON incident_reports (reporter_user_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_incident_reports_status ON incident_reports (status, severity)
                WHERE deleted_at IS NULL AND status IN ('open', 'investigating');
            CREATE INDEX idx_incident_reports_occurred_at ON incident_reports (occurred_at DESC)
                WHERE deleted_at IS NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON incident_reports
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            ALTER TABLE incident_reports ENABLE ROW LEVEL SECURITY;

            -- Read-only for the runtime role: the reporter sees their own report;
            -- staff/super_admin see all. No INSERT/UPDATE/DELETE policy by design —
            -- writes are system-authored (ah_system, BYPASSRLS).
            CREATE POLICY incident_reports_reporter_and_staff ON incident_reports
                FOR SELECT TO ah_runtime
                USING (
                    reporter_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS incident_reports CASCADE;');
    }
};
