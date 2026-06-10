<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN is_profile_public BOOLEAN NOT NULL DEFAULT false;

            COMMENT ON COLUMN users.is_profile_public IS 'User opted in to a publicly visible profile page. Off by default; requires explicit confirmation to enable.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            ALTER TABLE users DROP COLUMN IF EXISTS is_profile_public;
        SQL);
    }
};
