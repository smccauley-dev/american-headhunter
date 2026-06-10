<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_listings
                ADD COLUMN IF NOT EXISTS custom_contract_document_id UUID NULL;

            COMMENT ON COLUMN property_listings.custom_contract_document_id
                IS 'References DB 11 (Documents) documents.id — Ranch+ custom PDF uploaded at approval time';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_listings DROP COLUMN IF EXISTS custom_contract_document_id;
        SQL);
    }
};
