<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Per-record spot privacy for the member GPS map (SEC-024): the point
        // still lives only in DB 13, but when TRUE the marker is filtered out of
        // the map for everyone except the hunter who logged it. Service-layer
        // enforced — DB 5 has no RLS. Default FALSE = shown to co-hunters with
        // standing.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE harvest_logs
                ADD COLUMN hide_location_from_members BOOLEAN NOT NULL DEFAULT FALSE;

            ALTER TABLE wildlife_sightings
                ADD COLUMN hide_location_from_members BOOLEAN NOT NULL DEFAULT FALSE;

            COMMENT ON COLUMN harvest_logs.hide_location_from_members IS
                'TRUE hides this record''s map marker from other members with standing; the owner always sees it (SEC-024, service-layer enforced).';

            COMMENT ON COLUMN wildlife_sightings.hide_location_from_members IS
                'TRUE hides this record''s map marker from other members with standing; the owner always sees it (SEC-024, service-layer enforced).';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE harvest_logs DROP COLUMN IF EXISTS hide_location_from_members;
            ALTER TABLE wildlife_sightings DROP COLUMN IF EXISTS hide_location_from_members;
        SQL);
    }
};
