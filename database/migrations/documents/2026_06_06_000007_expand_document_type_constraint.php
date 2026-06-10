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
                DROP CONSTRAINT chk_documents_type,
                ADD CONSTRAINT chk_documents_type
                    CHECK (document_type IN (
                        'photo', 'video', 'pdf', 'contract',
                        'id_document', 'driver_license', 'hunting_license',
                        'other'
                    ));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE documents
                DROP CONSTRAINT chk_documents_type,
                ADD CONSTRAINT chk_documents_type
                    CHECK (document_type IN ('photo', 'video', 'pdf', 'contract', 'id_document', 'other'));
        SQL);
    }
};
