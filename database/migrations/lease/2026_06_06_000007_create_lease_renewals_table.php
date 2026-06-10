<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_renewals (
                id               UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id         UUID          NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                offered_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                offer_expires_at TIMESTAMPTZ   NOT NULL,
                new_start        DATE          NOT NULL,
                new_end          DATE          NOT NULL,
                new_price        NUMERIC(10,2) NOT NULL,
                status           VARCHAR(10)   NOT NULL DEFAULT 'pending'
                                     CHECK (status IN ('pending', 'accepted', 'rejected', 'expired')),
                responded_at     TIMESTAMPTZ   NULL,
                created_at       TIMESTAMPTZ   NOT NULL DEFAULT NOW()
                -- Append-only pattern: no updated_at, no deleted_at
            );

            CREATE INDEX idx_lease_renewals_lease_id ON lease_renewals (lease_id);
            CREATE INDEX idx_lease_renewals_status   ON lease_renewals (status);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_renewals CASCADE;');
    }
};
