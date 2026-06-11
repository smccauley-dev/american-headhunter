<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE lease_documents (
                id                   UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                lease_id             UUID        NOT NULL,
                document_id          UUID        NOT NULL,
                tag                  VARCHAR(50) NOT NULL,
                uploaded_by_user_id  UUID        NOT NULL,
                notes                TEXT,
                deleted_at           TIMESTAMPTZ,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT chk_lease_documents_tag CHECK (
                    tag IN (
                        'mla',
                        'fully_executed',
                        'amendment',
                        'addendum',
                        'insurance_certificate',
                        'property_map',
                        'hunting_rules',
                        'other'
                    )
                )
            );

            COMMENT ON TABLE lease_documents IS 'Documents attached to a lease with a descriptive tag. Physical file lives in DB 11 documents.';
            COMMENT ON COLUMN lease_documents.lease_id IS 'References leases.id in this database';
            COMMENT ON COLUMN lease_documents.document_id IS 'References DB 11 (Documents) documents.id';
            COMMENT ON COLUMN lease_documents.uploaded_by_user_id IS 'References DB 1 (Identity) users.id';

            CREATE INDEX idx_lease_documents_lease_id ON lease_documents (lease_id) WHERE deleted_at IS NULL;
            CREATE INDEX idx_lease_documents_document_id ON lease_documents (document_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            DROP TABLE IF EXISTS lease_documents;
        SQL);
    }
};
