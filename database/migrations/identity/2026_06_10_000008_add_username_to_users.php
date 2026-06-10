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
                ADD COLUMN username VARCHAR(50) NULL;

            CREATE UNIQUE INDEX uq_users_username ON users (username);

            COMMENT ON COLUMN users.username IS
                '@mention handle and public profile URL slug. '
                'Lowercase letters, numbers, underscores only. '
                'Set once when the user first enables a public profile — never changed after that.';
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->unprepared(<<<'SQL'
            DROP INDEX IF EXISTS uq_users_username;
            ALTER TABLE users DROP COLUMN IF EXISTS username;
        SQL);
    }
};
