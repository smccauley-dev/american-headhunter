<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'billing';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            -- SEC-043: ah_runtime is the application's non-owner runtime role, so RLS
            -- policies actually apply to it (the owner ah_app bypasses RLS and is used
            -- only for migrations/seeders/ETL). Grant it DML only — never ownership.
            -- The role is created by docker/postgres/init.sql (fresh installs); guard
            -- here so an existing cluster missing the role fails loudly instead of
            -- silently leaving the app unable to connect.
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ah_runtime') THEN
                    RAISE EXCEPTION 'Role ah_runtime is missing. Create it as a superuser before migrating (see docker/postgres/init.sql).';
                END IF;
            END$$;

            GRANT USAGE ON SCHEMA public TO ah_runtime;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ah_runtime;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ah_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ah_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO ah_runtime;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM ah_runtime;
            ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM ah_runtime;
            REVOKE SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public FROM ah_runtime;
            REVOKE USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public FROM ah_runtime;
            REVOKE USAGE ON SCHEMA public FROM ah_runtime;
        SQL);
    }
};
