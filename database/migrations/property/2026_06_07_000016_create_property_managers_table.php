<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            CREATE TABLE property_managers (
                id                  UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id         UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                user_id             UUID        NOT NULL,
                role                VARCHAR(20) NOT NULL
                                        CHECK (role IN ('co_owner', 'manager', 'operator')),
                granted_by_user_id  UUID        NOT NULL,
                granted_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                revoked_at          TIMESTAMPTZ NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            -- Prevent duplicate active grants for the same (property, user) pair
            CREATE UNIQUE INDEX uq_property_managers_active
                ON property_managers (property_id, user_id)
                WHERE revoked_at IS NULL;

            CREATE INDEX idx_property_managers_property_id ON property_managers (property_id);
            CREATE INDEX idx_property_managers_user_id     ON property_managers (user_id);
            CREATE INDEX idx_property_managers_active      ON property_managers (property_id)
                WHERE revoked_at IS NULL;

            COMMENT ON TABLE property_managers IS
                'Users who manage or operate a property on behalf of the owner. '
                'Check access via PropertyService::canManageProperty() — never query this table directly.';

            COMMENT ON COLUMN property_managers.user_id IS
                'References DB 1 (Identity) users.id';

            COMMENT ON COLUMN property_managers.granted_by_user_id IS
                'References DB 1 (Identity) users.id — must be property owner or staff';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(
            'DROP TABLE IF EXISTS property_managers;'
        );
    }
};
