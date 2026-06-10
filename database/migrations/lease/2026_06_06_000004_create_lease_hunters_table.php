<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE lease_hunters (
                id          UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                lease_id    UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                user_id     UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                role        VARCHAR(10) NOT NULL DEFAULT 'member'
                                CHECK (role IN ('primary', 'guest', 'member')),
                is_approved BOOLEAN     NOT NULL DEFAULT false,
                approved_at TIMESTAMPTZ NULL,
                invited_at  TIMESTAMPTZ NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at  TIMESTAMPTZ NULL
            );

            CREATE UNIQUE INDEX uq_lease_hunters_lease_user ON lease_hunters (lease_id, user_id) WHERE deleted_at IS NULL;
            CREATE        INDEX idx_lease_hunters_lease_id  ON lease_hunters (lease_id);
            CREATE        INDEX idx_lease_hunters_user_id   ON lease_hunters (user_id);

            CREATE TRIGGER trg_lease_hunters_updated_at
                BEFORE UPDATE ON lease_hunters
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS lease_hunters CASCADE;');
    }
};
