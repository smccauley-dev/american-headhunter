<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — give every incident a human-facing case number.
 *
 * Format: IR-<first 8 chars of the property's listing id, uppercased>-<NN>, where
 * NN is a per-listing sequence (01, 02, …). The listing id is resolved from the
 * incident's property_id at file time (assembled in the service layer — no cross-DB
 * FK). listing_id is denormalised here so the sequence is stable even if a property
 * is later re-listed. Both columns are nullable so the column add never blocks on a
 * cross-DB lookup; IncidentService::file() always populates them for new rows.
 *
 * The unique index is partial (incident_number IS NOT NULL) so it tolerates any
 * legacy row that could not be resolved to a listing.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE incident_reports
                ADD COLUMN listing_id      UUID,            -- References DB 2 (Property) property_listings.id
                ADD COLUMN incident_number VARCHAR(40);

            CREATE UNIQUE INDEX uq_incident_reports_number
                ON incident_reports (incident_number)
                WHERE incident_number IS NOT NULL;
        SQL);

        $this->backfill();
    }

    /**
     * Number the rows that predate this column. Each incident is resolved to its
     * property's first listing, then numbered per listing in occurrence order.
     */
    private function backfill(): void
    {
        $reports = DB::connection($this->connection)->table('incident_reports')
            ->orderBy('created_at')
            ->get(['id', 'property_id']);

        $sequenceByScope = [];

        foreach ($reports as $report) {
            $listingId = DB::connection('property')->table('property_listings')
                ->where('property_id', $report->property_id)
                ->orderBy('created_at')
                ->value('id');

            // Fall back to the property id when a listing can't be resolved.
            $scopeId = $listingId ?? $report->property_id;
            $seq     = ($sequenceByScope[$scopeId] = ($sequenceByScope[$scopeId] ?? 0) + 1);

            $number = sprintf('IR-%s-%02d', strtoupper(substr((string) $scopeId, 0, 8)), $seq);

            DB::connection($this->connection)->table('incident_reports')
                ->where('id', $report->id)
                ->update(['listing_id' => $listingId, 'incident_number' => $number]);
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS uq_incident_reports_number;
            ALTER TABLE incident_reports
                DROP COLUMN IF EXISTS incident_number,
                DROP COLUMN IF EXISTS listing_id;
        SQL);
    }
};
