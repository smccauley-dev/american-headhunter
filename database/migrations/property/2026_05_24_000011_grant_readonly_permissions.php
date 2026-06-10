<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA public TO ah_readonly;
            GRANT SELECT ON ALL TABLES IN SCHEMA public TO ah_readonly;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO ah_readonly;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            REVOKE SELECT ON ALL TABLES IN SCHEMA public FROM ah_readonly;
            REVOKE USAGE ON SCHEMA public FROM ah_readonly;
        SQL);
    }
};
