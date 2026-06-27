<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.x — capture the people involved in an incident.
 *
 * The "What happened" narrative is free text and unreliable for naming the people
 * involved. This adds parties_involved: a JSONB list where each line item is a
 * { full_name, is_minor } pair — the same dynamic multi-line shape as incident_items.
 *
 * is_minor is a plain boolean flag ("under 18 at the time of the incident"), captured
 * instead of a date of birth so no protected personal data (DOB) is stored.
 */
return new class extends Migration
{
    protected $connection = 'incidents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE incident_reports
                ADD COLUMN parties_involved JSONB NOT NULL DEFAULT '[]';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE incident_reports DROP COLUMN IF EXISTS parties_involved;
        SQL);
    }
};
