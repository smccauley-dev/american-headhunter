<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE hunter_credentials
                ADD COLUMN dl_document_id_back                UUID NULL,
                ADD COLUMN hunting_license_document_id_back   UUID NULL;

            COMMENT ON COLUMN hunter_credentials.dl_document_id_back              IS 'References DB 11 (Documents) documents.id — back side photo';
            COMMENT ON COLUMN hunter_credentials.hunting_license_document_id_back IS 'References DB 11 (Documents) documents.id — back side photo';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE hunter_credentials
                DROP COLUMN IF EXISTS dl_document_id_back,
                DROP COLUMN IF EXISTS hunting_license_document_id_back;
        SQL);
    }
};
