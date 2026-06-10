<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE esignature_requests (
                id                   UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id             UUID         NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                provider_request_id  VARCHAR(255) NOT NULL,
                status               VARCHAR(15)  NOT NULL DEFAULT 'pending'
                                         CHECK (status IN ('pending', 'completed', 'declined', 'expired')),
                document_document_id UUID         NULL,  -- References DB 11 (Documents) documents.id — the signed PDF
                requested_at         TIMESTAMPTZ  NOT NULL,
                completed_at         TIMESTAMPTZ  NULL,
                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_esignature_requests_provider ON esignature_requests (provider_request_id);
            CREATE        INDEX idx_esignature_requests_lease   ON esignature_requests (lease_id);
            CREATE        INDEX idx_esignature_requests_status  ON esignature_requests (status);

            CREATE TRIGGER trg_esignature_requests_updated_at
                BEFORE UPDATE ON esignature_requests
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS esignature_requests CASCADE;');
    }
};
