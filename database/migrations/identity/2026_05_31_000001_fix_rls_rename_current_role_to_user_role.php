<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        // app.current_role conflicts with the PostgreSQL built-in current_role keyword.
        // Rename all RLS policies to use app.user_role instead.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS users_admin_read          ON users;
            DROP POLICY IF EXISTS user_profiles_admin_read  ON user_profiles;
            DROP POLICY IF EXISTS bgcheck_staff_and_own     ON background_check_results;

            CREATE POLICY users_admin_read ON users
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true)
                    IN ('staff', 'super_admin')
                );

            CREATE POLICY user_profiles_admin_read ON user_profiles
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true)
                    IN ('staff', 'super_admin')
                );

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
        // Intentionally drop without recreating — restoring app.current_role would
        // silently fail (PostgreSQL keyword conflict) and leave tables without
        // functional RLS policies. A full rollback of the original migration is required.
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS users_admin_read          ON users;
            DROP POLICY IF EXISTS user_profiles_admin_read  ON user_profiles;
            DROP POLICY IF EXISTS bgcheck_staff_and_own     ON background_check_results;
        SQL);
    }
};
