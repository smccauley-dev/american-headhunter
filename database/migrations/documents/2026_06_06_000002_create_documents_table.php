<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE documents (
                id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                owner_user_id     UUID        NOT NULL,               -- References DB 1 (Identity) users.id
                document_type     VARCHAR(20) NOT NULL,
                status            VARCHAR(20) NOT NULL DEFAULT 'pending',
                original_filename VARCHAR(500),
                mime_type         VARCHAR(100),
                size_bytes        BIGINT,
                storage_bucket    VARCHAR(100),                       -- Bucket/container name in storage provider
                storage_key       TEXT,                               -- Full object key path in the bucket
                storage_provider  VARCHAR(20) NOT NULL DEFAULT 'garage',
                width_px          INT,                                -- Photos only
                height_px         INT,                                -- Photos only
                duration_seconds  INT,                                -- Videos only
                checksum_sha256   CHAR(64),                           -- SHA-256 of file bytes; verified on serve
                is_public         BOOLEAN     NOT NULL DEFAULT false,
                virus_scan_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                virus_scanned_at  TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at        TIMESTAMPTZ,

                CONSTRAINT chk_documents_type
                    CHECK (document_type IN ('photo', 'video', 'pdf', 'contract', 'id_document', 'other')),
                CONSTRAINT chk_documents_status
                    CHECK (status IN ('pending', 'processing', 'ready', 'failed', 'deleted')),
                CONSTRAINT chk_documents_storage_provider
                    CHECK (storage_provider IN ('garage', 'azure_blob')),
                CONSTRAINT chk_documents_virus_scan_status
                    CHECK (virus_scan_status IN ('pending', 'clean', 'infected'))
            );

            CREATE INDEX idx_documents_owner_user_id ON documents (owner_user_id)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_documents_status ON documents (status, virus_scan_status)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_documents_type ON documents (document_type)
                WHERE deleted_at IS NULL;
            CREATE INDEX idx_documents_virus_pending ON documents (created_at)
                WHERE virus_scan_status = 'pending' AND deleted_at IS NULL;
            CREATE INDEX idx_documents_storage_key ON documents (storage_key)
                WHERE storage_key IS NOT NULL;

            CREATE TRIGGER set_updated_at BEFORE UPDATE ON documents
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS documents CASCADE;');
    }
};
