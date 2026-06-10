<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE clubs (
                id             UUID          NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                owner_user_id  UUID          NOT NULL,  -- References DB 1 (Identity) users.id
                name           VARCHAR(150)  NOT NULL,
                slug           VARCHAR(150)  NOT NULL,
                description    TEXT          NULL,
                status         VARCHAR(20)   NOT NULL DEFAULT 'active'
                                   CHECK (status IN ('active', 'inactive', 'suspended')),
                max_members    SMALLINT      NULL,
                membership_fee NUMERIC(10,2) NULL,
                is_public      BOOLEAN       NOT NULL DEFAULT false,
                created_at     TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                deleted_at     TIMESTAMPTZ   NULL
            );

            CREATE UNIQUE INDEX uq_clubs_slug    ON clubs (slug) WHERE deleted_at IS NULL;
            CREATE        INDEX idx_clubs_owner  ON clubs (owner_user_id);
            CREATE        INDEX idx_clubs_status ON clubs (status);

            CREATE TRIGGER trg_clubs_updated_at
                BEFORE UPDATE ON clubs
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE club_members (
                id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                club_id    UUID        NOT NULL REFERENCES clubs (id) ON DELETE CASCADE,
                user_id    UUID        NOT NULL,  -- References DB 1 (Identity) users.id
                role       VARCHAR(10) NOT NULL DEFAULT 'member'
                               CHECK (role IN ('owner', 'admin', 'member')),
                status     VARCHAR(15) NOT NULL DEFAULT 'active'
                               CHECK (status IN ('active', 'invited', 'suspended')),
                joined_at  TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                deleted_at TIMESTAMPTZ NULL
            );

            CREATE UNIQUE INDEX uq_club_members_club_user ON club_members (club_id, user_id) WHERE deleted_at IS NULL;
            CREATE        INDEX idx_club_members_club_id  ON club_members (club_id);
            CREATE        INDEX idx_club_members_user_id  ON club_members (user_id);

            CREATE TRIGGER trg_club_members_updated_at
                BEFORE UPDATE ON club_members
                FOR EACH ROW EXECUTE FUNCTION trigger_set_updated_at();

            CREATE TABLE club_leases (
                id         UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                club_id    UUID        NOT NULL REFERENCES clubs (id) ON DELETE CASCADE,
                lease_id   UUID        NOT NULL REFERENCES leases (id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE UNIQUE INDEX uq_club_leases_club_lease ON club_leases (club_id, lease_id);
            CREATE        INDEX idx_club_leases_lease_id  ON club_leases (lease_id);
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP TABLE IF EXISTS club_leases  CASCADE;
            DROP TABLE IF EXISTS club_members CASCADE;
            DROP TABLE IF EXISTS clubs        CASCADE;
        SQL);
    }
};
