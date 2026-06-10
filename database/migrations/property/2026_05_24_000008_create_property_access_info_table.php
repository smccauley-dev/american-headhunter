<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            CREATE TABLE property_access_info (
                id                    UUID        NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                property_id           UUID        NOT NULL REFERENCES properties (id) ON DELETE CASCADE,
                access_info_encrypted TEXT        NOT NULL,  -- pgp_sym_encrypt — JSON blob: gate codes, wifi, cabin codes, directions
                updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_by_user_id    UUID        NULL  -- References DB 1 (Identity) users.id
            );

            CREATE UNIQUE INDEX uq_property_access_info_property ON property_access_info (property_id);

            -- RLS: restrict to staff/super_admin at DB level.
            -- Full lessee authorization (cross-DB check against ah_lease.leases) is enforced
            -- in PropertyService::getAccessInfo() — cross-DB joins are not possible in RLS.
            ALTER TABLE property_access_info ENABLE ROW LEVEL SECURITY;

            CREATE POLICY access_info_restricted ON property_access_info
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS property_access_info CASCADE');
    }
};
