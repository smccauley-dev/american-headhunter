<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- leases: lessee, lessor, and staff can read
            ALTER TABLE leases ENABLE ROW LEVEL SECURITY;

            CREATE POLICY leases_parties_and_staff ON leases
                FOR SELECT TO ah_app
                USING (
                    lessee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR lessor_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            -- lease_hunters: own record, lessor of the lease, or staff
            ALTER TABLE lease_hunters ENABLE ROW LEVEL SECURITY;

            CREATE POLICY lease_hunters_self_and_lessor ON lease_hunters
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR lease_id IN (
                        SELECT id FROM leases
                        WHERE lessor_user_id = current_setting('app.current_user_id', true)::UUID
                    )
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            -- check_ins: own record, lessor of the lease, or staff
            ALTER TABLE check_ins ENABLE ROW LEVEL SECURITY;

            CREATE POLICY check_ins_own_or_lessor ON check_ins
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR lease_id IN (
                        SELECT id FROM leases
                        WHERE lessor_user_id = current_setting('app.current_user_id', true)::UUID
                    )
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            -- lease_notes: staff and landowners see all; hunters see non-internal notes for their lease only
            ALTER TABLE lease_notes ENABLE ROW LEVEL SECURITY;

            CREATE POLICY lease_notes_visibility ON lease_notes
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true) IN ('staff', 'super_admin', 'landowner')
                    OR (
                        is_internal = false
                        AND lease_id IN (
                            SELECT id FROM leases
                            WHERE lessee_user_id = current_setting('app.current_user_id', true)::UUID
                        )
                    )
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS lease_notes_visibility        ON lease_notes;
            ALTER TABLE lease_notes   DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS check_ins_own_or_lessor       ON check_ins;
            ALTER TABLE check_ins     DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS lease_hunters_self_and_lessor ON lease_hunters;
            ALTER TABLE lease_hunters DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS leases_parties_and_staff      ON leases;
            ALTER TABLE leases        DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
