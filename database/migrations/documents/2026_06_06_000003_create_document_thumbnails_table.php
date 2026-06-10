<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'documents';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE document_thumbnails (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                document_id UUID        NOT NULL REFERENCES documents (id),
                variant     VARCHAR(20) NOT NULL,
                storage_key TEXT        NOT NULL,
                width_px    INT         NOT NULL,
                height_px   INT         NOT NULL,
                size_bytes  BIGINT,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                -- No updated_at — thumbnails are replaced by deleting and re-inserting
                -- No deleted_at — deleted when parent document is deleted

                CONSTRAINT chk_document_thumbnails_variant
                    CHECK (variant IN ('thumb_sm', 'thumb_md', 'thumb_lg', 'poster')),
                CONSTRAINT uq_document_thumbnails_variant UNIQUE (document_id, variant)
            );

            CREATE INDEX idx_document_thumbnails_document_id ON document_thumbnails (document_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS document_thumbnails CASCADE;');
    }
};
