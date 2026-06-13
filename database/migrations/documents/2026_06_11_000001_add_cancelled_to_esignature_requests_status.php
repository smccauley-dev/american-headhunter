<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE esignature_requests DROP CONSTRAINT IF EXISTS esignature_requests_status_check;
            ALTER TABLE esignature_requests ADD CONSTRAINT esignature_requests_status_check
                CHECK (status IN ('pending', 'out_for_signature', 'completed', 'declined', 'expired', 'error', 'cancelled'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE esignature_requests DROP CONSTRAINT IF EXISTS esignature_requests_status_check;
            ALTER TABLE esignature_requests ADD CONSTRAINT esignature_requests_status_check
                CHECK (status IN ('pending', 'out_for_signature', 'completed', 'declined', 'expired', 'error'));
        SQL);
    }
};
