<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP POLICY IF EXISTS access_info_restricted ON property_access_info;

            CREATE POLICY access_info_restricted ON property_access_info
                FOR SELECT TO ah_app
                USING (
                    current_setting('app.user_role', true) IN ('staff', 'super_admin')
                );
        SQL);
    }

    public function down(): void
    {
        // Drop only — recreating with app.current_role would silently break the policy
        // (PostgreSQL keyword conflict). Full rollback requires running the original migration's down().
        DB::connection($this->connection)->unprepared(
            'DROP POLICY IF EXISTS access_info_restricted ON property_access_info;'
        );
    }
};
