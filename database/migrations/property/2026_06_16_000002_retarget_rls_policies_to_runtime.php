<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-043 stage 2 — re-target the property_access_info RLS policy from the
 * table owner (ah_app, which bypasses RLS) to the non-owner runtime role
 * (ah_runtime) so it actually enforces. Semantics unchanged: only staff /
 * super_admin may read at the DB level; full lessee authorization is still
 * enforced in PropertyService::getAccessInfo(). Safe while the app remains on
 * ah_app — the owner keeps full access until the connection is flipped.
 */
return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS access_info_restricted ON property_access_info;

            CREATE POLICY access_info_restricted ON property_access_info
                FOR SELECT TO ah_runtime
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS access_info_restricted ON property_access_info;

            CREATE POLICY access_info_restricted ON property_access_info
                FOR SELECT TO ah_app
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));
        SQL);
    }
};
