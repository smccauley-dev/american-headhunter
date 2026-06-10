<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_notes (
                id             UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id       UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                author_user_id UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                note           TEXT        NOT NULL,
                is_internal    BOOLEAN     NOT NULL DEFAULT true,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at     TIMESTAMPTZ NULL
            );

            CREATE INDEX idx_lease_notes_lease_id ON lease_notes (lease_id);

            CREATE TRIGGER trg_lease_notes_updated_at
                BEFORE UPDATE ON lease_notes
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_notes CASCADE;');
    }
};
