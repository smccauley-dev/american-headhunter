<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'wildlife';

    public function up(): void
    {
        // Highest alert band (0 / 75 / 90) already notified to the landowner for
        // this quota, so CheckQuotaAlerts notifies once per threshold crossing and
        // never re-nags on subsequent daily runs.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE harvest_quotas
                ADD COLUMN alert_threshold_notified SMALLINT NOT NULL DEFAULT 0
                    CHECK (alert_threshold_notified IN (0, 75, 90));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('ALTER TABLE harvest_quotas DROP COLUMN IF EXISTS alert_threshold_notified');
    }
};
