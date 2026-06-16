<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-045 — restore field check-in/out under ah_runtime RLS.
 *
 * check_ins had only a FOR SELECT policy. With RLS enabled and no permissive
 * write policy, PostgreSQL default-denies every INSERT/UPDATE for non-owner
 * roles, so member check-in (INSERT) and check-out (UPDATE) silently failed
 * once SEC-043 flipped the runtime connection from the owner (ah_app, bypasses
 * RLS) to ah_runtime. This adds the missing write policies.
 *
 * Self-service only: a hunter may write their own check_in rows; staff and
 * super_admin retain write access for support corrections (mirroring the
 * existing check_ins_own_or_lessor SELECT policy). The lessor can SEE rows on
 * their lease but cannot author them. Purely additive — no existing policy is
 * modified, so reads and admin/system (ah_system, BYPASSRLS) paths are
 * unaffected.
 */
return new class extends Migration
{
    protected $connection = 'lease';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS check_ins_insert_self ON check_ins;
            DROP POLICY IF EXISTS check_ins_update_self ON check_ins;

            CREATE POLICY check_ins_insert_self ON check_ins
                FOR INSERT TO ah_runtime
                WITH CHECK (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );

            CREATE POLICY check_ins_update_self ON check_ins
                FOR UPDATE TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                )
                WITH CHECK (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS check_ins_insert_self ON check_ins;
            DROP POLICY IF EXISTS check_ins_update_self ON check_ins;
        SQL);
    }
};
