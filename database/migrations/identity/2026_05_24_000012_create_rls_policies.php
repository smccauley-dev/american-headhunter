<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users ENABLE ROW LEVEL SECURITY;

            CREATE POLICY users_self_read ON users
                FOR SELECT TO ah_app
                USING (id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY users_admin_read ON users
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true)
                    IN ('staff', 'super_admin')
                );

            CREATE POLICY users_self_write ON users
                FOR UPDATE TO ah_app
                USING (id = current_setting('app.current_user_id', true)::UUID);

            ALTER TABLE user_profiles ENABLE ROW LEVEL SECURITY;

            CREATE POLICY user_profiles_self_read ON user_profiles
                FOR SELECT TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            CREATE POLICY user_profiles_admin_read ON user_profiles
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true)
                    IN ('staff', 'super_admin')
                );

            CREATE POLICY user_profiles_self_write ON user_profiles
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            ALTER TABLE mfa_configurations ENABLE ROW LEVEL SECURITY;

            CREATE POLICY mfa_own_user_only ON mfa_configurations
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            ALTER TABLE api_keys ENABLE ROW LEVEL SECURITY;

            CREATE POLICY api_keys_own_user ON api_keys
                FOR ALL TO ah_app
                USING (user_id = current_setting('app.current_user_id', true)::UUID);

            ALTER TABLE background_check_results ENABLE ROW LEVEL SECURITY;

            CREATE POLICY bgcheck_staff_and_own ON background_check_results
                FOR SELECT TO ah_app
                USING (
                    user_id = current_setting('app.current_user_id', true)::UUID
                    OR current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS bgcheck_staff_and_own        ON background_check_results;
            ALTER TABLE background_check_results DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS api_keys_own_user            ON api_keys;
            ALTER TABLE api_keys DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS mfa_own_user_only            ON mfa_configurations;
            ALTER TABLE mfa_configurations DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS user_profiles_self_write     ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_admin_read     ON user_profiles;
            DROP POLICY IF EXISTS user_profiles_self_read      ON user_profiles;
            ALTER TABLE user_profiles DISABLE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS users_self_write             ON users;
            DROP POLICY IF EXISTS users_admin_read             ON users;
            DROP POLICY IF EXISTS users_self_read              ON users;
            ALTER TABLE users DISABLE ROW LEVEL SECURITY;
        SQL);
    }
};
