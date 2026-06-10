<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_photos (
                id          UUID         NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id UUID         NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                document_id UUID         NOT NULL,  -- References DB 11 (Documents) documents.id
                sort_order  SMALLINT     NOT NULL DEFAULT 0,
                caption     VARCHAR(255) NULL,
                is_primary  BOOLEAN      NOT NULL DEFAULT false,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                deleted_at  TIMESTAMPTZ  NULL
            );

            CREATE INDEX idx_property_photos_property_id ON property_photos (property_id);
            CREATE INDEX idx_property_photos_sort_order  ON property_photos (property_id, sort_order);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_photos CASCADE');
    }
};
