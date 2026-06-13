<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE property_photos ADD COLUMN tags JSONB NOT NULL DEFAULT '[]'::jsonb;
            CREATE INDEX idx_property_photos_tags_gin ON property_photos USING GIN (tags);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_property_photos_tags_gin;
            ALTER TABLE property_photos DROP COLUMN IF EXISTS tags;
        SQL);
    }
};
