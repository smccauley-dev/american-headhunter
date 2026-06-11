<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE esignature_requests
                ADD COLUMN template_document_id UUID NULL;

            COMMENT ON COLUMN esignature_requests.template_document_id
                IS 'References DB 11 documents.id — the custom contract PDF uploaded during approval';

            CREATE INDEX idx_esignature_requests_template_document_id
                ON esignature_requests (template_document_id)
                WHERE template_document_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP INDEX IF EXISTS idx_esignature_requests_template_document_id;
            ALTER TABLE esignature_requests DROP COLUMN IF EXISTS template_document_id;
        SQL);
    }
};
