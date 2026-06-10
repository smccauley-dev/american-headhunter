<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- Partial index covers only active, non-deleted listings.
            -- The index size stays bounded by active listing count — not total historical count.
            -- Archived/expired/deleted rows are physically excluded from this index.
            CREATE INDEX idx_property_listings_active
                ON property_listings (property_id, season_start, season_end)
                WHERE deleted_at IS NULL AND status = 'active';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP INDEX IF EXISTS idx_property_listings_active;'
        );
    }
};
