<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — multiple incident types per report.
 *
 * One real-world safety event can be several things at once — e.g. a fire at the
 * ranch house AND a medical injury. This adds incident_items: a JSONB list where
 * each line item carries its OWN { type, severity, occurred_at }. The report keeps
 * one shared narrative, location, injury/authority flags, status lifecycle, and
 * case number.
 *
 * The existing scalar columns incident_type / severity / occurred_at are kept and
 * become a service-maintained "lead" derived from the items (lead type = first
 * item; severity = the worst across items; occurred_at = the earliest), so all the
 * existing NOT NULL columns, CHECK constraints, badges, filters, sorts, and the
 * (status, severity) / (occurred_at DESC) indexes keep working unchanged.
 *
 * Item-level type/severity values are validated at the request/service layer (they
 * live inside JSONB, like evidence_document_ids) — the scalar lead columns remain
 * CHECK-guarded. 'fire' is added to the type vocabulary here.
 *
 * Backfill: every existing row gets a single-item list mirroring its current scalar
 * type/severity/occurred_at, so nothing loses fidelity.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE incident_reports
                ADD COLUMN incident_items JSONB NOT NULL DEFAULT '[]';

            -- Add 'fire' to the lead-type vocabulary.
            ALTER TABLE incident_reports DROP CONSTRAINT IF EXISTS chk_incident_reports_type;
            ALTER TABLE incident_reports ADD CONSTRAINT chk_incident_reports_type
                CHECK (incident_type IN (
                    'hunting_accident', 'trespassing', 'property_damage',
                    'wildlife_encounter', 'medical', 'fire', 'other'
                ));

            -- Backfill a single-item list from the existing scalar fields.
            UPDATE incident_reports
            SET incident_items = jsonb_build_array(
                jsonb_build_object(
                    'type', incident_type,
                    'severity', severity,
                    'occurred_at', to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
                )
            )
            WHERE incident_items = '[]'::jsonb;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE incident_reports DROP COLUMN IF EXISTS incident_items;

            ALTER TABLE incident_reports DROP CONSTRAINT IF EXISTS chk_incident_reports_type;
            ALTER TABLE incident_reports ADD CONSTRAINT chk_incident_reports_type
                CHECK (incident_type IN (
                    'hunting_accident', 'trespassing', 'property_damage',
                    'wildlife_encounter', 'medical', 'other'
                ));
        SQL);
    }
};
