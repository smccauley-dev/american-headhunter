<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_application_hunters
                ADD COLUMN dl_document_id_back                UUID NULL,
                ADD COLUMN hunting_license_document_id_back   UUID NULL;

            COMMENT ON COLUMN lease_application_hunters.dl_document_id_back              IS 'References DB 11 (Documents) documents.id — back side photo';
            COMMENT ON COLUMN lease_application_hunters.hunting_license_document_id_back IS 'References DB 11 (Documents) documents.id — back side photo';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE lease_application_hunters
                DROP COLUMN IF EXISTS dl_document_id_back,
                DROP COLUMN IF EXISTS hunting_license_document_id_back;
        SQL);
    }
};
