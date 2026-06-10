<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE print_jobs (
                id            UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                job_type      VARCHAR(30) NOT NULL,
                owner_user_id UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                status        VARCHAR(20) NOT NULL DEFAULT 'queued',
                target_id     UUID        NOT NULL,  -- The entity this PDF is generated for
                target_type   VARCHAR(50) NOT NULL,  -- e.g. 'lease', 'harvest_log', 'property'
                document_id   UUID        REFERENCES documents (id),  -- NULL until PDF generated
                created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                -- No deleted_at — completed jobs retained for re-download

                CONSTRAINT chk_print_jobs_type
                    CHECK (job_type IN ('lease_agreement', 'harvest_report', 'property_map', 'field_guide')),
                CONSTRAINT chk_print_jobs_status
                    CHECK (status IN ('queued', 'processing', 'ready', 'failed'))
            );

            CREATE INDEX idx_print_jobs_owner  ON print_jobs (owner_user_id, created_at DESC);
            CREATE INDEX idx_print_jobs_status ON print_jobs (status)
                WHERE status IN ('queued', 'processing');
            CREATE INDEX idx_print_jobs_target ON print_jobs (target_type, target_id);

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON print_jobs
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS print_jobs CASCADE;');
    }
};
