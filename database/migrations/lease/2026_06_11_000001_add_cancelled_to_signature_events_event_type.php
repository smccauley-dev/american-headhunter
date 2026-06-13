<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE signature_events DROP CONSTRAINT IF EXISTS signature_events_event_type_check;
            ALTER TABLE signature_events ADD CONSTRAINT signature_events_event_type_check
                CHECK (event_type IN ('sent', 'viewed', 'signed', 'declined', 'completed', 'cancelled'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE signature_events DROP CONSTRAINT IF EXISTS signature_events_event_type_check;
            ALTER TABLE signature_events ADD CONSTRAINT signature_events_event_type_check
                CHECK (event_type IN ('sent', 'viewed', 'signed', 'declined', 'completed'));
        SQL);
    }
};
