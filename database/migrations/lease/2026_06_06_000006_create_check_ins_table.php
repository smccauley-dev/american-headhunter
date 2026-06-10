<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE check_ins (
                id                UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id          UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                user_id           UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                stand_location_id UUID        NULL,      -- References DB 13 (Geospatial) stand_locations.id
                checked_in_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                checked_out_at    TIMESTAMPTZ NULL,
                notes             TEXT        NULL,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
                -- Append-only log: no updated_at, no deleted_at
            );

            CREATE INDEX idx_check_ins_lease_id      ON check_ins (lease_id);
            CREATE INDEX idx_check_ins_user_id       ON check_ins (user_id);
            CREATE INDEX idx_check_ins_checked_in_at ON check_ins (checked_in_at);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS check_ins CASCADE;');
    }
};
