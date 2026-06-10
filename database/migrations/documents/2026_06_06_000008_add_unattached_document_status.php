<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE documents
                DROP CONSTRAINT chk_documents_status,
                ADD CONSTRAINT chk_documents_status
                    CHECK (status IN ('unattached', 'pending', 'processing', 'ready', 'failed', 'deleted'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE documents
                DROP CONSTRAINT chk_documents_status,
                ADD CONSTRAINT chk_documents_status
                    CHECK (status IN ('pending', 'processing', 'ready', 'failed', 'deleted'));
        SQL);
    }
};
