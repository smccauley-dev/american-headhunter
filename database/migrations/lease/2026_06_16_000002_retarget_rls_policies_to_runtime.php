<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-043 stage 2 — re-target the lease RLS policies from the table owner
 * (ah_app, which bypasses RLS) to the non-owner runtime role (ah_runtime) so
 * they actually enforce, and harden the user-id casts with NULLIF(..., '') so
 * an unauthenticated/empty context resolves to NULL instead of throwing on
 * ''::UUID. Semantics otherwise unchanged. Safe while the app remains on
 * ah_app — the owner keeps full access until the connection is flipped.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS leases_parties_and_staff      ON leases;
            DROP POLICY IF EXISTS lease_hunters_self_and_lessor ON lease_hunters;
            DROP POLICY IF EXISTS check_ins_own_or_lessor       ON check_ins;
            DROP POLICY IF EXISTS lease_notes_visibility        ON lease_notes;

            CREATE POLICY leases_parties_and_staff ON leases
                FOR SELECT TO ah_runtime
                USING (
                    lessee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR lessor_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY lease_hunters_self_and_lessor ON lease_hunters
                FOR SELECT TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR lease_id IN (
                        SELECT id FROM leases
                        WHERE lessor_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    )
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY check_ins_own_or_lessor ON check_ins
                FOR SELECT TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR lease_id IN (
                        SELECT id FROM leases
                        WHERE lessor_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    )
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY lease_notes_visibility ON lease_notes
                FOR SELECT TO ah_runtime
                USING (
                    current_setting('app.user_role', true) IN ('staff', 'super_admin', 'landowner')
                    OR (
                        is_internal = false
                        AND lease_id IN (
                            SELECT id FROM leases
                            WHERE lessee_user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                        )
                    )
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS leases_parties_and_staff      ON leases;
            DROP POLICY IF EXISTS lease_hunters_self_and_lessor ON lease_hunters;
            DROP POLICY IF EXISTS check_ins_own_or_lessor       ON check_ins;
            DROP POLICY IF EXISTS lease_notes_visibility        ON lease_notes;

            CREATE POLICY leases_parties_and_staff ON leases
                FOR SELECT TO ah_app
                USING (
                    lessee_user_id = current_setting('app.current_user_id', true)::UUID
                    OR lessor_user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

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
};
