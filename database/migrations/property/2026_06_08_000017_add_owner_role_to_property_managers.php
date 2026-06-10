<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'property';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_managers
                DROP CONSTRAINT IF EXISTS property_managers_role_check,
                ADD CONSTRAINT property_managers_role_check
                    CHECK (role IN ('owner', 'co_owner', 'manager', 'operator'));
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<SQL
            ALTER TABLE property_managers
                DROP CONSTRAINT IF EXISTS property_managers_role_check,
                ADD CONSTRAINT property_managers_role_check
                    CHECK (role IN ('co_owner', 'manager', 'operator'));
        SQL);
    }
};
