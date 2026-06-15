<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEC-043 stage 2 — make the identity RLS policies actually enforceable.
 *
 * The original policies targeted ah_app, which OWNS these tables and therefore
 * bypasses RLS entirely (a table owner is exempt unless FORCE ROW LEVEL
 * SECURITY is set). They were no-ops. Re-target every policy to ah_runtime —
 * the non-owner role the application connects as — so the policies apply.
 *
 * Also harden the user-id comparison: the request middleware sets
 * app.current_user_id to '' (empty string) for unauthenticated requests, and a
 * bare ''::UUID throws "invalid input syntax for type uuid". Wrap every cast in
 * NULLIF(..., '') so an absent user resolves to NULL (row simply not matched)
 * instead of erroring the whole query. Semantics are otherwise unchanged.
 *
 * Safe to deploy while the app still connects as ah_app: the owner keeps full
 * access regardless of policy contents, so nothing breaks until the connection
 * is flipped to ah_runtime in a later stage.
 */
return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS users_self_read              ON users;
            DROP POLICY IF EXISTS users_admin_read             ON users;
            DROP POLICY IF EXISTS users_self_write             ON users;
            DROP POLICY IF EXISTS user_profiles_self_read      ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_admin_read     ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_self_write     ON user_profiles;
            DROP POLICY IF EXISTS mfa_own_user_only            ON mfa_configurations;
            DROP POLICY IF EXISTS api_keys_own_user            ON api_keys;
            DROP POLICY IF EXISTS bgcheck_staff_and_own        ON background_check_results;

            CREATE POLICY users_self_read ON users
                FOR SELECT TO ah_runtime
                USING (id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY users_admin_read ON users
                FOR SELECT TO ah_runtime
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));

            CREATE POLICY users_self_write ON users
                FOR UPDATE TO ah_runtime
                USING (id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY user_profiles_self_read ON user_profiles
                FOR SELECT TO ah_runtime
                USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY user_profiles_admin_read ON user_profiles
                FOR SELECT TO ah_runtime
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));

            CREATE POLICY user_profiles_self_write ON user_profiles
                FOR ALL TO ah_runtime
                USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY mfa_own_user_only ON mfa_configurations
                FOR ALL TO ah_runtime
                USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY api_keys_own_user ON api_keys
                FOR ALL TO ah_runtime
                USING (user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID);

            CREATE POLICY bgcheck_staff_and_own ON background_check_results
                FOR SELECT TO ah_runtime
                USING (
                    user_id = NULLIF(current_setting('app.current_user_id', true), '')::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        // Restore the previous (live) state: same policies, targeted at ah_app,
        // with the bare ::UUID cast.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS users_self_read              ON users;
            DROP POLICY IF EXISTS users_admin_read             ON users;
            DROP POLICY IF EXISTS users_self_write             ON users;
            DROP POLICY IF EXISTS user_profiles_self_read      ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_admin_read     ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_self_write     ON user_profiles;
            DROP POLICY IF EXISTS mfa_own_user_only            ON mfa_configurations;
            DROP POLICY IF EXISTS api_keys_own_user            ON api_keys;
            DROP POLICY IF EXISTS bgcheck_staff_and_own        ON background_check_results;

            CREATE POLICY users_self_read ON users
                FOR SELECT TO ah_app
                USING (id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY users_admin_read ON users
                FOR SELECT TO ah_app
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));

            CREATE POLICY users_self_write ON users
                FOR UPDATE TO ah_app
                USING (id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY user_profiles_self_read ON user_profiles
                FOR SELECT TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY user_profiles_admin_read ON user_profiles
                FOR SELECT TO ah_app
                USING (current_setting('app.user_role', true) IN ('staff', 'super_admin'));

            CREATE POLICY user_profiles_self_write ON user_profiles
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY mfa_own_user_only ON mfa_configurations
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY api_keys_own_user ON api_keys
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY bgcheck_staff_and_own ON background_check_results
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }
};
