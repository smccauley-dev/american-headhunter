<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'geospatial';

    public function up(): void
    {
        $conn = DB::connection($this->connection);

        // Order matters: postgis_topology and postgis_tiger_geocoder depend on postgis
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "postgis"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "postgis_topology"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "fuzzystrmatch"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "postgis_tiger_geocoder"');
        $conn->statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        $conn->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION trigger_set_updated_at()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.updated_at = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement(
            'DROP FUNCTION IF EXISTS trigger_set_updated_at() CASCADE;'
        );
    }
};
